<?php
namespace Deployer;

// Used to make a writable directory by a server.
// Used in `chown` and `acl` modes of {{writable_mode}}.
// Attempts automatically to detect http user in process list.
use Deployer\Exception\RunException;

set('http_user', function () {
    $candidates = explode("\n", run("ps axo comm,user | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx|[c]addy' | grep -v root | sort | awk '{print \$NF}' | uniq"));
    $httpUser = array_shift($candidates);

    if (empty($httpUser)) {
        throw new \RuntimeException(
            "Can't detect http user name.\n" .
            "Please setup `http_user` config parameter."
        );
    }

    return $httpUser;
});

// Used to make a writable directory by a server.
// Used in `chgrp` mode of {{writable_mode}} only.
// Attempts automatically to detect http user in process list.
set('http_group', function () {
    $candidates = explode("\n", run("ps axo comm,group | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx|[c]addy' | grep -v root | sort | awk '{print \$NF}' | uniq"));
    $httpGroup = array_shift($candidates);

    if (empty($httpGroup)) {
        throw new \RuntimeException(
            "Can't detect http user name.\n" .
            "Please setup `http_group` config parameter."
        );
    }

    return $httpGroup;
});

// List of writable dirs.
set('writable_dirs', []);

// One of:
// - chown
// - chgrp
// - chmod
// - acl
set('writable_mode', 'chgrp');

// Using sudo in writable commands?
set('writable_use_sudo', false);

// Use recursive mode (-R)?
set('writable_recursive', false);

// The chmod mode.
set('writable_chmod_mode', '0755');

desc('Make writable dirs');
task('deploy:writable', function () {
    $dirs = join(' ', get('writable_dirs'));
    $mode = get('writable_mode');
    $recursive = get('writable_recursive') ? '-R' : '';
    $sudo = get('writable_use_sudo') ? 'sudo' : '';

    if (empty($dirs)) {
        return;
    }
    // Check that we don't have absolute path
    if (strpos($dirs, ' /') !== false) {
        throw new \RuntimeException('Absolute path not allowed in config parameter `writable_dirs`.');
    }

    cd('{{release_path}}');

    // Create directories if they don't exist
    run("mkdir -p $dirs");

    if ($mode === 'chown') {
        $httpUser = get('http_user');
        // Change owner.
        // -L   traverse every symbolic link to a directory encountered

        run("$sudo chown -L $recursive $httpUser $dirs");
    } elseif ($mode === 'chgrp') {
        // Change group ownership.
        try {
            // -L    traverse every symbolic link to a directory encountered
            run("$sudo chgrp -H $recursive {{http_group}} $dirs");
        } catch (RunException $exception) {
            warning("Make sure `{{remote_user}}` is in `{{http_group}}` group: `usermod -a -G {{http_group}} {{remote_user}}`");
            throw  $exception;
        }
    } elseif ($mode === 'chmod') {
        run("$sudo chmod $recursive {{writable_chmod_mode}} $dirs");
    } elseif ($mode === 'acl') {
        $httpUser = get('http_user');
        if (strpos(run("chmod 2>&1; true"), '+a') !== false) {
            // Try OS-X specific setting of access-rights

            run("$sudo chmod +a \"$httpUser allow delete,write,append,file_inherit,directory_inherit\" $dirs");
            run("$sudo chmod +a \"`whoami` allow delete,write,append,file_inherit,directory_inherit\" $dirs");
        } elseif (commandExist('setfacl')) {
            if (!empty($sudo)) {
                run("$sudo setfacl -L $recursive -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
                run("$sudo setfacl -dL $recursive -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
            } else {
                // When running without sudo, exception may be thrown
                // if executing setfacl on files created by http user (in directory that has been setfacl before).
                // These directories/files should be skipped.
                // Now, we will check each directory for ACL and only setfacl for which has not been set before.
                $writeableDirs = get('writable_dirs');
                foreach ($writeableDirs as $dir) {
                    // Check if ACL has been set or not
                    $hasfacl = run("getfacl -p $dir | grep \"^user:$httpUser:.*w\" | wc -l");
                    // Set ACL for directory if it has not been set before
                    if (!$hasfacl) {
                        run("setfacl -L $recursive -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
                        run("setfacl -dL $recursive -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
                    }
                }
            }
        } else {
            $alias = currentHost()->getAlias();
            throw new \RuntimeException("Can't set writable dirs with ACL.\nInstall ACL with next command:\ndep run 'sudo apt-get install acl' -- $alias");
        }
    } else {
        throw new \RuntimeException("Unknown writable_mode `$mode`.");
    }
});
