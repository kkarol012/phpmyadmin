<?php
/**
 * Git inspection
 */
declare(strict_types=1);

namespace PhpMyAdmin;

use DirectoryIterator;
use PhpMyAdmin\Utils\HttpRequest;
use stdClass;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use function array_key_exists;
use function array_shift;
use function basename;
use function bin2hex;
use function count;
use function date;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fopen;
use function fread;
use function fseek;
use function function_exists;
use function gzuncompress;
use function implode;
use function in_array;
use function intval;
use function is_dir;
use function is_file;
use function json_decode;
use function ord;
use function preg_match;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function unpack;

/**
 * Git class to manipulate Git data
 */
class Git
{

    /**
     * Build a Git class
     *
     * @var Config
     */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * detects if Git revision
     *
     * @param string $git_location (optional) verified git directory
     */
    public function isGitRevision(&$git_location = null): bool
    {
        // PMA config check
        if (! $this->config->get('ShowGitRevision')) {
            return false;
        }

        // caching
        if (isset($_SESSION['is_git_revision'])
            && array_key_exists('git_location', $_SESSION)
        ) {
            // Define location using cached value
            $git_location = $_SESSION['git_location'];

            return $_SESSION['is_git_revision'];
        }

        // find out if there is a .git folder
        // or a .git file (--separate-git-dir)
        $git = '.git';
        if (is_dir($git)) {
            if (@is_file($git . '/config')) {
                $git_location = $git;
            } else {
                $_SESSION['git_location'] = null;
                $_SESSION['is_git_revision'] = false;

                return false;
            }
        } elseif (is_file($git)) {
            $contents = file_get_contents($git);
            $gitmatch = [];
            // Matches expected format
            if (! preg_match(
                '/^gitdir: (.*)$/',
                $contents,
                $gitmatch
            )) {
                $_SESSION['git_location'] = null;
                $_SESSION['is_git_revision'] = false;

                return false;
            } elseif (@is_dir($gitmatch[1])) {
                //Detected git external folder location
                $git_location = $gitmatch[1];
            } else {
                $_SESSION['git_location'] = null;
                $_SESSION['is_git_revision'] = false;

                return false;
            }
        } else {
            $_SESSION['git_location'] = null;
            $_SESSION['is_git_revision'] = false;

            return false;
        }
        // Define session for caching
        $_SESSION['git_location'] = $git_location;
        $_SESSION['is_git_revision'] = true;

        return true;
    }

    /**
     * Un pack a commit with gzuncompress
     *
     * @param string $gitFolder The Git folder
     * @param string $hash      The commit hash
     * @return array|false|null
     */
    private function unPackGz(string $gitFolder, string $hash)
    {
        $commit = false;

        $gitFileName = $gitFolder . '/objects/'
            . substr($hash, 0, 2) . '/' . substr($hash, 2);
        if (@file_exists($gitFileName)) {
            $commit = @file_get_contents($gitFileName);

            if ($commit === false) {
                $this->config->set('PMA_VERSION_GIT', 0);

                return null;
            }

            $commit = explode("\0", gzuncompress($commit), 2);
            $commit = explode("\n", $commit[1]);
            $_SESSION['PMA_VERSION_COMMITDATA_' . $hash] = $commit;
        } else {
            $pack_names = [];
            // work with packed data
            $packs_file = $gitFolder . '/objects/info/packs';
            $packs = '';

            if (@file_exists($packs_file)) {
                $packs = @file_get_contents($packs_file);
            }

            if ($packs) {
                // File exists. Read it, parse the file to get the names of the
                // packs. (to look for them in .git/object/pack directory later)
                foreach (explode("\n", $packs) as $line) {
                    // skip blank lines
                    if (strlen(trim($line)) == 0) {
                        continue;
                    }
                    // skip non pack lines
                    if ($line[0] != 'P') {
                        continue;
                    }
                    // parse names
                    $pack_names[] = substr($line, 2);
                }
            } else {
                // '.git/objects/info/packs' file can be missing
                // (atlease in mysGit)
                // File missing. May be we can look in the .git/object/pack
                // directory for all the .pack files and use that list of
                // files instead
                $dirIterator = new DirectoryIterator(
                    $gitFolder . '/objects/pack'
                );
                foreach ($dirIterator as $file_info) {
                    $file_name = $file_info->getFilename();
                    // if this is a .pack file
                    if ($file_info->isFile() && substr($file_name, -5) == '.pack'
                    ) {
                        $pack_names[] = $file_name;
                    }
                }
            }
            $hash = strtolower($hash);
            foreach ($pack_names as $pack_name) {
                $index_name = str_replace('.pack', '.idx', $pack_name);

                // load index
                $index_data = @file_get_contents(
                    $gitFolder . '/objects/pack/' . $index_name
                );
                if (! $index_data) {
                    continue;
                }
                // check format
                if (substr($index_data, 0, 4) != "\377tOc") {
                    continue;
                }
                // check version
                $version = unpack('N', substr($index_data, 4, 4));
                if ($version[1] != 2) {
                    continue;
                }
                // parse fanout table
                $fanout = unpack(
                    'N*',
                    substr($index_data, 8, 256 * 4)
                );

                // find where we should search
                $firstbyte = intval(substr($hash, 0, 2), 16);
                // array is indexed from 1 and we need to get
                // previous entry for start
                if ($firstbyte == 0) {
                    $start = 0;
                } else {
                    $start = $fanout[$firstbyte];
                }
                $end = $fanout[$firstbyte + 1];

                // stupid linear search for our sha
                $found = false;
                $offset = 8 + (256 * 4);
                for ($position = $start; $position < $end; $position++) {
                    $sha = strtolower(
                        bin2hex(
                            substr($index_data, $offset + ($position * 20), 20)
                        )
                    );
                    if ($sha == $hash) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    continue;
                }
                // read pack offset
                $offset = 8 + (256 * 4) + (24 * $fanout[256]);
                $pack_offset = unpack(
                    'N',
                    substr($index_data, $offset + ($position * 4), 4)
                );
                $pack_offset = $pack_offset[1];

                // open pack file
                $pack_file = fopen(
                    $gitFolder . '/objects/pack/' . $pack_name,
                    'rb'
                );
                if ($pack_file === false) {
                    continue;
                }
                // seek to start
                fseek($pack_file, $pack_offset);

                // parse header
                $header = ord(fread($pack_file, 1));
                $type = ($header >> 4) & 7;
                $hasnext = ($header & 128) >> 7;
                $size = $header & 0xf;
                $offset = 4;

                while ($hasnext) {
                    $byte = ord(fread($pack_file, 1));
                    $size |= ($byte & 0x7f) << $offset;
                    $hasnext = ($byte & 128) >> 7;
                    $offset += 7;
                }

                // we care only about commit objects
                if ($type != 1) {
                    continue;
                }

                // read data
                $commit = fread($pack_file, $size);
                fclose($pack_file);
                if ($commit !== false) {
                    $commit = gzuncompress($commit);
                    if ($commit !== false) {
                        $commit = explode("\n", $commit);
                    }
                }
                $_SESSION['PMA_VERSION_COMMITDATA_' . $hash] = $commit;
            }
        }
        return $commit;
    }

    /**
     * Extract committer, author and message from commit body
     *
     * @param array $commit The commit body
     * @return array<int,array<string,string>|string>
     */
    private function extractDataFormTextBody(array $commit): array
    {
        $author = [
            'name' => '',
            'email' => '',
            'date' => '',
        ];
        $committer = [
            'name' => '',
            'email' => '',
            'date' => '',
        ];

        do {
            $dataline = array_shift($commit);
            $datalinearr = explode(' ', $dataline, 2);
            $linetype = $datalinearr[0];
            if (in_array($linetype, ['author', 'committer'])) {
                $user = $datalinearr[1];
                preg_match('/([^<]+)<([^>]+)> ([0-9]+)( [^ ]+)?/', $user, $user);
                $user2 = [
                    'name' => trim($user[1]),
                    'email' => trim($user[2]),
                    'date' => date('Y-m-d H:i:s', (int) $user[3]),
                ];
                if (isset($user[4])) {
                    $user2['date'] .= $user[4];
                }
                $$linetype = $user2;
            }
        } while ($dataline != '');
        $message = trim(implode(' ', $commit));

        return [$author, $committer, $message];
    }

    /**
     * Is the commit remote
     *
     * @param mixed   $commit         The commit
     * @param boolean $isRemoteCommit Is the commit remote ?, will be modified by reference
     * @param string  $hash           The commit hash
     * @return stdClass|null The commit body from the GitHub API
     */
    private function isRemoteCommit(&$commit, bool &$isRemoteCommit, string $hash): ?stdClass
    {
        $httpRequest = new HttpRequest();

        // check if commit exists in Github
        if ($commit !== false
            && isset($_SESSION['PMA_VERSION_REMOTECOMMIT_' . $hash])
        ) {
            $isRemoteCommit = $_SESSION['PMA_VERSION_REMOTECOMMIT_' . $hash];
        } else {
            $link = 'https://www.phpmyadmin.net/api/commit/' . $hash . '/';
            $is_found = $httpRequest->create($link, 'GET');
            switch ($is_found) {
                case false:
                    $isRemoteCommit = false;
                    $_SESSION['PMA_VERSION_REMOTECOMMIT_' . $hash] = false;
                    break;
                case null:
                    // no remote link for now, but don't cache this as Github is down
                    $isRemoteCommit = false;
                    break;
                default:
                    $isRemoteCommit = true;
                    $_SESSION['PMA_VERSION_REMOTECOMMIT_' . $hash] = true;
                    if ($commit === false) {
                        // if no local commit data, try loading from Github
                        return json_decode((string) $is_found);
                    }
                    break;
            }
        }
        return null;
    }

    /**
     * detects Git revision, if running inside repo
     */
    public function checkGitRevision(): void
    {
        // find out if there is a .git folder
        $gitFolder = '';
        if (! $this->isGitRevision($gitFolder)) {
            $this->config->set('PMA_VERSION_GIT', 0);

            return;
        }

        $ref_head = @file_get_contents($gitFolder . '/HEAD');

        if (! $ref_head) {
            $this->config->set('PMA_VERSION_GIT', 0);

            return;
        }

        $common_dir_contents = @file_get_contents($gitFolder . '/commondir');

        if ($common_dir_contents !== false) {
            $gitFolder .= DIRECTORY_SEPARATOR . trim($common_dir_contents);
        }

        $branch = false;
        // are we on any branch?
        if (strpos($ref_head, '/') !== false) {
            // remove ref: prefix
            $ref_head = substr(trim($ref_head), 5);
            if (strpos($ref_head, 'refs/heads/') === 0) {
                $branch = substr($ref_head, 11);
            } else {
                $branch = basename($ref_head);
            }

            $ref_file = $gitFolder . '/' . $ref_head;
            if (@file_exists($ref_file)) {
                $hash = @file_get_contents($ref_file);
                if ($hash === false) {
                    $this->config->set('PMA_VERSION_GIT', 0);

                    return;
                }
                $hash = trim($hash);
            } else {
                // deal with packed refs
                $packed_refs = @file_get_contents($gitFolder . '/packed-refs');
                if ($packed_refs === false) {
                    $this->config->set('PMA_VERSION_GIT', 0);

                    return;
                }
                // split file to lines
                $ref_lines = explode(PHP_EOL, $packed_refs);
                foreach ($ref_lines as $line) {
                    // skip comments
                    if ($line[0] == '#') {
                        continue;
                    }
                    // parse line
                    $parts = explode(' ', $line);
                    // care only about named refs
                    if (count($parts) != 2) {
                        continue;
                    }
                    // have found our ref?
                    if ($parts[1] == $ref_head) {
                        $hash = $parts[0];
                        break;
                    }
                }
                if (! isset($hash)) {
                    $this->config->set('PMA_VERSION_GIT', 0);

                    // Could not find ref
                    return;
                }
            }
        } else {
            $hash = trim($ref_head);
        }

        $commit = false;
        if (! preg_match('/^[0-9a-f]{40}$/i', $hash)) {
            $commit = false;
        } elseif (isset($_SESSION['PMA_VERSION_COMMITDATA_' . $hash])) {
            $commit = $_SESSION['PMA_VERSION_COMMITDATA_' . $hash];
        } elseif (function_exists('gzuncompress')) {
            $commit = $this->unPackGz($gitFolder, $hash);
            if ($commit === null) {
                return;
            }
        }

        $is_remote_commit = false;
        $commit_json = $this->isRemoteCommit(
            $commit, // Will be modified if necessary by the function
            $is_remote_commit, // Will be modified if necessary by the function
            $hash
        );

        $is_remote_branch = false;
        if ($is_remote_commit && $branch !== false) {
            // check if branch exists in Github
            if (isset($_SESSION['PMA_VERSION_REMOTEBRANCH_' . $hash])) {
                $is_remote_branch = $_SESSION['PMA_VERSION_REMOTEBRANCH_' . $hash];
            } else {
                $httpRequest = new HttpRequest();
                $link = 'https://www.phpmyadmin.net/api/tree/' . $branch . '/';
                $is_found = $httpRequest->create($link, 'GET', true);
                switch ($is_found) {
                    case true:
                        $is_remote_branch = true;
                        $_SESSION['PMA_VERSION_REMOTEBRANCH_' . $hash] = true;
                        break;
                    case false:
                        $is_remote_branch = false;
                        $_SESSION['PMA_VERSION_REMOTEBRANCH_' . $hash] = false;
                        break;
                    case null:
                        // no remote link for now, but don't cache this as Github is down
                        $is_remote_branch = false;
                        break;
                }
            }
        }

        if ($commit !== false) {
            [$author, $committer, $message] = $this->extractDataFormTextBody($commit);
        } elseif (isset($commit_json->author, $commit_json->committer, $commit_json->message)) {
            $author = [
                'name' => $commit_json->author->name,
                'email' => $commit_json->author->email,
                'date' => $commit_json->author->date,
            ];
            $committer = [
                'name' => $commit_json->committer->name,
                'email' => $commit_json->committer->email,
                'date' => $commit_json->committer->date,
            ];
            $message = trim($commit_json->message);
        } else {
            $this->config->set('PMA_VERSION_GIT', 0);

            return;
        }

        $this->config->set('PMA_VERSION_GIT', 1);
        $this->config->set('PMA_VERSION_GIT_COMMITHASH', $hash);
        $this->config->set('PMA_VERSION_GIT_BRANCH', $branch);
        $this->config->set('PMA_VERSION_GIT_MESSAGE', $message);
        $this->config->set('PMA_VERSION_GIT_AUTHOR', $author);
        $this->config->set('PMA_VERSION_GIT_COMMITTER', $committer);
        $this->config->set('PMA_VERSION_GIT_ISREMOTECOMMIT', $is_remote_commit);
        $this->config->set('PMA_VERSION_GIT_ISREMOTEBRANCH', $is_remote_branch);
    }
}