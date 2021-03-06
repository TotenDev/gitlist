<?php

namespace Git;

use Git\Commit\Commit;
use Git\Model\Tree;
use Git\Model\Blob;
use Git\Model\Diff;

class Repository
{
    protected $path;
    protected $client;

    public function __construct($path, Client $client)
    {
        $this->setPath($path);
        $this->setClient($client);
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function create()
    {
        mkdir($this->getPath());
        $this->getClient()->run($this, 'init');

        return $this;
    }

    public function getConfig($key)
    {
        $key = $this->getClient()->run($this, 'config ' . $key);
        return trim($key);
    }

    public function setConfig($key, $value)
    {
        $this->getClient()->run($this, "config $key \"$value\"");

        return $this;
    }
    
    /**
     * Add untracked files
     * 
     * @access public
     * @param mixed $files Files to be added to the repository
     */
    public function add($files = '.')
    {
        if(is_array($files)) {
            $files = implode(' ', $files);
        }
        
        $this->getClient()->run($this, "add $files");

        return $this;
    }

    /**
     * Add all untracked files
     * 
     * @access public
     */
    public function addAll()
    {
        $this->getClient()->run($this, "add -A");

        return $this;
    }
    
    /**
     * Commit changes to the repository
     * 
     * @access public
     * @param string $message Description of the changes made
     */
    public function commit($message)
    {
        $this->getClient()->run($this, "commit -m '$message'");

        return $this;
    }
    
    /**
     * Checkout a branch
     * 
     * @access public
     * @param string $branch Branch to be checked out
     */
    public function checkout($branch)
    {
        $this->getClient()->run($this, "checkout $branch");

        return $this;
    }

    /**
     * Pull repository changes
     * 
     * @access public
     */
    public function pull()
    {
        $this->getClient()->run($this, "pull");

        return $this;
    }
    
    /**
     * Update remote references
     * 
     * @access public
     * @param string $repository Repository to be pushed
     * @param string $refspec Refspec for the push
     */
    public function push($repository = null, $refspec = null)
    {
        $command = "push";
        
        if($repository) {
            $command .= " $repository";
        } 
        
        if($refspec) {
            $command .= " $refspec";
        }
        
        $this->getClient()->run($this, $command);

        return $this;
    }
    
    /**
     * Show a list of the repository branches
     * 
     * @access public
     * @return array List of branches
     */
    public function getBranches()
    {
        $branches = $this->getClient()->run($this, "branch");
        $branches = explode("\n", $branches);
        $branches = array_filter(preg_replace('/[\*\s]/', '', $branches));
        
        return $branches;
    }
    
    /**
     * Show the current repository branch
     * 
     * @access public
     * @return string Current repository branch
     */
    public function getCurrentBranch()
    {
        $branches = $this->getClient()->run($this, "branch");
        $branches = explode("\n", $branches);
        
        foreach($branches as $branch) {
            if($branch[0] == '*') {
                return substr($branch, 2);
            }
        }
    }
    
    /**
     * Check if a specified branch exists
     * 
     * @access public
     * @param string $branch Branch to be checked
     * @return boolean True if the branch exists
     */
    public function hasBranch($branch)
    {
        $branches = $this->getBranches();
        $status = in_array($branch, $branches);
        return $status;
    }

    /**
     * Create a new repository branch
     * 
     * @access public
     * @param string $branch Branch name
     */
    public function createBranch($branch)
    {
        $this->getClient()->run($this, "branch $branch");
    }
    
    /**
     * Show a list of the repository tags
     * 
     * @access public
     * @return array List of tags
     */
    public function getTags()
    {
        $tags = $this->getClient()->run($this, "tag");
        $tags = explode("\n", $tags);

        if (empty($tags[0])) {
            return NULL;
        }
        
        return $tags;
    }
    
    /**
     * Show the repository commit log
     * 
     * @access public
     * @return array Commit log
     */
    public function getCommits($file = null)
    {
        $command = 'log --pretty=format:\'"%h": {"hash": "%H", "short_hash": "%h", "tree": "%T", "parent": "%P", "author": "%an", "author_email": "%ae", "date": "%at", "commiter": "%cn", "commiter_email": "%ce", "commiter_date": "%ct", "message": "%f"}\'';
        
        if ($file) {
            $command .= " $file";
        }

        $logs = $this->getClient()->run($this, $command);
        $logs = str_replace("\n", ',', $logs);
        $logs = json_decode("{ $logs }", true);

        foreach ($logs as $log) {
            $log['message'] = str_replace('-', ' ', $log['message']);
            $commit = new Commit;
            $commit->importData($log);
            $commits[] = $commit;
        }

        return $commits;
    }

    public function getRelatedCommits($hash)
    {
        $logs = $this->getClient()->run($this, 'log --pretty=format:\'"%h": {"hash": "%H", "short_hash": "%h", "tree": "%T", "parent": "%P", "author": "%an", "author_email": "%ae", "date": "%at", "commiter": "%cn", "commiter_email": "%ce", "commiter_date": "%ct", "message": "%f"}\'');
        $logs = str_replace("\n", ',', $logs);
        $logs = json_decode("{ $logs }", true);

        foreach ($logs as $log) {
            $log['message'] = str_replace('-', ' ', $log['message']);
            $logTree = $this->getClient()->run($this, 'diff-tree -t -r ' . $log['hash']);
            $lines = explode("\n", $logTree);
            array_shift($lines);
            $files = array();

            foreach ($lines as $key => $line) {
                if (empty($line)) {
                    unset($lines[$key]);
                    continue;
                }

                $files[] = preg_split("/[\s]+/", $line);
            }

            // Now let's find the commits who have our hash within them
            foreach ($files as $file) {
                if ($file[1] == 'commit') {
                    continue;
                }

                if ($file[3] == $hash) {
                    $commit = new Commit;
                    $commit->importData($log);
                    $commits[] = $commit;
                    break;
                }
            }
        }

        return $commits;
    }

    public function getCommit($commit)
    {
        $logs = $this->getClient()->run($this, 'show --pretty=format:\'{"hash": "%H", "short_hash": "%h", "tree": "%T", "parent": "%P", "author": "%an", "author_email": "%ae", "date": "%at", "commiter": "%cn", "commiter_email": "%ce", "commiter_date": "%ct", "message": "%f"}\' ' . $commit);
        $logs = explode("\n", $logs);

        // Read commit metadata
        $data = json_decode($logs[0], true);
        $data['message'] = str_replace('-', ' ', $data['message']);
        $commit = new Commit;
        $commit->importData($data);
        unset($logs[0]);

        // Read diff logs
        foreach ($logs as $log) {
            if ('diff' === substr($log, 0, 4)) {
                if (isset($diff)) {
                    $diffs[] = $diff;
                }

                $diff = new Diff;
                continue;
            }

            if ('index' === substr($log, 0, 5)) {
                $diff->setIndex($log);
                continue;
            }

            if ('---' === substr($log, 0, 3)) {
                $diff->setOld($log);
                continue;
            }

            if ('+++' === substr($log, 0, 3)) {
                $diff->setNew($log);
                continue;
            }

            $diff->addLine($log);
        }

        if (isset($diff)) {
            $diffs[] = $diff;
        }

        $commit->setDiffs($diffs);

        return $commit;
    }

    public function getAuthorStatistics()
    {
        $logs = $this->getClient()->run($this, 'log --pretty=format:\'%an||%ae\'');
        $logs = explode("\n", $logs);
        $logs = array_count_values($logs);
        arsort($logs);

        foreach ($logs as $user => $count) {
            $user = explode('||', $user);
            $data[] = array('name' => $user[0], 'email' => $user[1], 'commits' => $count); 
        }
        
        return $data;
    }

    public function getStatistics($branch)
    {
        // Calculate amount of files, extensions and file size
        $logs = $this->getClient()->run($this, 'ls-tree -r -l ' . $branch);
        $lines = explode("\n", $logs);
        $files = array();
        $data['extensions'] = array();
        $data['size'] = 0;
        $data['files'] = 0;

        foreach ($lines as $key => $line) {
            if (empty($line)) {
                unset($lines[$key]);
                continue;
            }

            $files[] = preg_split("/[\s]+/", $line);
        }

        foreach ($files as $file) {
            if ($file[1] == 'blob') {
                $data['files']++;
            }

            if (is_numeric($file[3])) {
                $data['size'] += $file[3];
            }

            if (($pos = strrpos($file[4], '.')) !== FALSE) {
                $data['extensions'][] = substr($file[4], $pos);
            }
        }

        $data['extensions'] = array_count_values($data['extensions']);
        arsort($data['extensions']);

        return $data;
    }

    /**
     * Get the Tree for the provided folder
     * 
     * @param string $tree Folder that will be parsed
     * @return Tree Instance of Tree for the provided folder
     */
    public function getTree($tree)
    {
        $tree = new Tree($tree, $this->getClient(), $this);
        $tree->parse();
        return $tree;
    }

    /**
     * Get the Blob for the provided file
     * 
     * @param string $blob File that will be parsed
     * @return Blob Instance of Blob for the provided file
     */
    public function getBlob($blob)
    {
        return new Blob($blob, $this->getClient(), $this);
    }

    /**
     * Blames the provided file and parses the output
     * 
     * @param string $file File that will be blamed
     * @return array Commits hashes containing the lines
     */
    public function getBlame($file)
    {
        $logs = $this->getClient()->run($this, "blame -s $file");
        $logs = explode("\n", $logs);

        foreach ($logs as $log) {
            if ($log == '') {
                continue;
            }

            $split = preg_split("/[a-zA-Z0-9^]{8}[\s]+[0-9]+\)/", $log);
            preg_match_all("/([a-zA-Z0-9^]{8})[\s]+([0-9]+)\)/", $log, $match);

            $commit = $match[1][0];

            if (!isset($blame[$commit]['line'])) {
                $blame[$commit]['line'] = '';
            }

            $blame[$commit]['line'] .= PHP_EOL . $split[1];
        }

        return $blame;
    }

    /**
     * Get the current Repository path
     * 
     * @return string Path where the repository is located
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Set the current Repository path
     * 
     * @param string $path Path where the repository is located
     */
    public function setPath($path)
    {
        $this->path = $path;
    }
}
