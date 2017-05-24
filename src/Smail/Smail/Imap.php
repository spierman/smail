<?php
namespace Smail;

use Smail\MailBase;
use Smail\Mime\Rfc822Header;
use Smail\Mime\ComMime;
use Smail\Util\ComDate;
use Smail\Util\ComFunc;
use Smail\Util\ComDection;
use Smail\Util\MailConfig;

class Imap extends MailBase
{

    public function __construct($username, $password)
    {
        parent::__construct();
        $this->username = $username;
        $this->password = $password;
        $connection_pros = MailConfig::getConnectionPro($username);
        $this->imap_server = $connection_pros[2];
        $this->imap_port = $connection_pros[3];
        $this->imap_auth_mech = $connection_pros[0];
        $this->use_tls = $connection_pros[1];
        $this->mail_domain = $connection_pros[6];
        $this->imap_server_type = $connection_pros[8];
        $this->login();
    }

    /**
     * user filter the special email
     *
     * @deprecated
     *
     */
    public function smimap_user_filters()
    {
        $aStatus = $this->smimap_status_messages('INBOX', array( // TODO
            'MESSAGES'
        ));
        if ($aStatus['MESSAGES']) {
            $this->mailboxSelect('INBOX');
            $id = array();
            $filters = array();
            for ($i = 0, $num = count($filters); $i < $num; $i ++) {
                if (! $this->isMailboxExists($this->imapStream, $filters[$i]['folder'])) {
                    continue;
                }
                if ($filters[$i]['where'] == 'To or Cc') {
                    $id = $this->filter_search_and_delete($this->imapStream, 'TO', $filters[$i]['what'], $filters[$i]['folder'], $filters_user_scan, $id);
                    $id = $this->filter_search_and_delete($this->imapStream, 'CC', $filters[$i]['what'], $filters[$i]['folder'], $filters_user_scan, $id);
                } else {
                    $id = $this->filter_search_and_delete($this->imapStream, $filters[$i]['where'], $filters[$i]['what'], $filters[$i]['folder'], $filters_user_scan, $id);
                }
            }
        }
    }

    /**
     * Creates and runs the IMAP command to filter messages
     *
     * @param string $imap
     *            TODO: Document this parameter
     * @param string $where
     *            Which part of the message to search (TO, CC, SUBJECT, etc...)
     * @param string $what
     *            String to search for
     * @param string $where_to
     *            Folder it will move to
     * @param string $user_scan
     *            Whether to search all or just unseen
     * @param string $del_id
     *            TODO: Document this parameter
     * @access private
     */
    private function filter_search_and_delete($imap, $where, $what, $where_to, $user_scan, $del_id)
    {
        $allow_charset_search = true;
        $uid_support = true;
        
        if (strtolower($where_to) == 'inbox') {
            return array();
        }
        
        if ($user_scan == 'new') {
            $category = 'UNSEEN';
        } else {
            $category = 'ALL';
        }
        $category .= ' UNDELETED';
        
        if ($allow_charset_search) {
            $search_str = 'SEARCH CHARSET ' . 'UTF-8' . ' ' . $category;
        } else {
            $search_str = 'SEARCH CHARSET US-ASCII ' . $category;
        }
        if ($where == 'Header') {
            $what = explode(':', $what);
            $where = trim($where . ' ' . $what[0]);
            $what = addslashes(trim($what[1]));
        }
        // see comments in squirrelmail sqimap_search function
        if ($this->imap_server_type == 'macosx' || $this->imap_server_type == 'hmailserver') {
            $search_str .= ' ' . $where . ' ' . $what;
            $read = $this->smimap_run_command_list($imap, $search_str, true, $response, $message, $uid_support);
        } else {
            $lit = array();
            $lit['command'] = $search_str . ' ' . $where;
            $lit['literal_args'][] = $what;
            $read = $this->smimap_run_literal_command($imap, $lit, true, $response, $message, $uid_support);
        }
        /* read data back from IMAP */
        // This may have problems with EIMS due to it being goofy
        for ($r = 0, $num = count($read); $r < $num && substr($read[$r], 0, 8) != '* SEARCH'; $r ++) {}
        if ($response == 'OK') {
            $ids = explode(' ', $read[$r]);
            if ($this->isMailboxExists($imap, $where_to)) {
                /*
                 * why we do n calls instead of just one. It is safer to copy
                 * messages one by one, but code does not call expunge after
                 * message is copied and quota limits are not resolved.
                 */
                for ($j = 2, $num = count($ids); $j < $num; $j ++) {
                    $id = trim($ids[$j]);
                    if ($this->smimap_msg_move($imap, $id, $where_to)) {
                        $del_id[] = $id;
                    }
                }
            }
        }
        return $del_id;
    }

    /**
     * Checks whether or not the specified mailbox exists
     *
     * @param
     *            $mailbox
     * @return boolean
     */
    public function isMailboxExists($mailbox)
    {
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $mbx = $this->smimap_run_command("LIST \"\" \"$mailbox\"", true, $response, $message);
        if (isset($mbx[0])) {
            return isset($mbx[0]);
        } else {
            throw new \Exception('mailbox not exists');
        }
    }

    /**
     * select a mailbox
     *
     * @param string $mailbox            
     * @return ArrayObject $result PERMANENTFLAGS FLAGS RIGHTS
     */
    public function mailboxSelect($mailbox)
    {
        $auto_expunge = true;
        if (strstr($mailbox, '../') || substr($mailbox, 0, 1) == '/') {
            $this->logout();
            throw new \Exception(sprintf("Invalid mailbox name: %s", $mailbox));
        }
        // cleanup $mailbox in order to prevent IMAP injection attacks
        $mailbox = str_replace(array(
            "\r",
            "\n"
        ), array(
            "",
            ""
        ), $mailbox);
        $read = $this->smimap_run_command("SELECT \"$mailbox\"", true, $response, $message);
        $result = array();
        for ($i = 0, $cnt = count($read); $i < $cnt; $i ++) {
            if (preg_match('/^\*\s+OK\s\[(\w+)\s(\w+)\]/', $read[$i], $regs)) {
                $result[strtoupper($regs[1])] = $regs[2];
            } else 
                if (preg_match('/^\*\s([0-9]+)\s(\w+)/', $read[$i], $regs)) {
                    $result[strtoupper($regs[2])] = $regs[1];
                } else {
                    if (preg_match("/PERMANENTFLAGS(.*)/i", $read[$i], $regs)) {
                        $regs[1] = trim(preg_replace(array(
                            "/\(/",
                            "/\)/",
                            "/\]/"
                        ), '', $regs[1]));
                        $result['PERMANENTFLAGS'] = $regs[1];
                    } else 
                        if (preg_match("/FLAGS(.*)/i", $read[$i], $regs)) {
                            $regs[1] = trim(preg_replace(array(
                                "/\(/",
                                "/\)/"
                            ), '', $regs[1]));
                            $result['FLAGS'] = $regs[1];
                        }
                }
        }
        if (preg_match('/^\[(.+)\]/', $message, $regs)) {
            $result['RIGHTS'] = $regs[1];
        }
        if ($auto_expunge) {
            $tmp = $this->smimap_run_command('EXPUNGE', false, $a, $b);
        }
        return $result;
    }

    /**
     * create a mailbox if use zh_cn please turn your charset to utf-8
     *
     * @param string $mailbox            
     * @param string $type            
     * @return string $response.':'.$message
     */
    public function mailboxCreate($mailbox, $type = '')
    {
        $delimiter = $this->getMailboxDelimiter();
        if (strtolower($type) == 'noselect') {
            $create_mailbox = $mailbox . $delimiter;
        } else {
            $create_mailbox = $mailbox;
        }
        $read_ary = $this->smimap_run_command("CREATE \"$create_mailbox\"", true, $response, $message);
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $this->mailboxSubscribe($create_mailbox);
        return array(
            'data' => $response . ':' . $message
        );
    }

    /**
     * show the mailbox that created
     *
     * @param string $mailbox            
     * @return $response
     */
    public function mailboxSubscribe($mailbox)
    {
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $read_ary = $this->smimap_run_command("SUBSCRIBE \"$mailbox\"", true, $response, $message);
        return array(
            'data' => $response . ':' . $message
        );
    }

    /**
     * Unsubscribes from an existing folder
     *
     * @param
     *            $mailbox
     * @return $response
     */
    public function mailboxUnsubscribe($mailbox)
    {
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $read_ary = $this->smimap_run_command("UNSUBSCRIBE \"$mailbox\"", false, $response, $message);
        return array(
            'data' => $response . ':' . $message
        );
    }

    /**
     * delete the mailbox
     *
     * @param string $mailbox            
     * @return boolean
     */
    public function mailboxDelete($mailbox)
    {
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $this->mailboxUnsubscribe($mailbox);
        if ($this->isMailboxExists($mailbox)) {
            $read_ary = $this->smimap_run_command("DELETE \"$mailbox\"", true, $response, $message);
            if ($response !== 'OK') {
                $this->mailboxSubscribe($mailbox);
                throw new Exception($response);
            }
            return array(
                'data' => $response . ':' . $message
            );
        }
    }

    /**
     * Determines if the user is subscribed to the folder or not
     *
     * @param string $mailbox            
     * @return boolean
     */
    public function mailboxIsSubscribed($mailbox)
    {
        $boxesall = $this->smimap_mailbox_list(true);
        foreach ($boxesall as $ref) {
            if ($ref['unformatted'] == $mailbox) {
                return true;
            }
        }
        return false;
    }

    /**
     * Expunges a mailbox, ie.
     * delete all contents.
     *
     * @param
     *            $mailbox
     * @param
     *            $handle_errors
     * @param
     *            string or array $id
     * @return int $cnt
     */
    public function mailboxExpunge($mailbox, $id = '')
    {
        if ($id) {
            if (is_array($id)) {
                $id = $this->smimap_message_list_squisher($id);
            }
            $id = ' ' . $id;
            $uid = true;
        } else {
            $uid = false;
        }
        $read = $this->smimap_run_command('EXPUNGE' . $id, true, $response, $message, $uid);
        $cnt = 0;
        if (is_array($read)) {
            foreach ($read as $r) {
                if (preg_match('/^\*\s[0-9]+\sEXPUNGE/AUi', $r, $regs)) {
                    $cnt ++;
                }
            }
        }
        return array(
            'msg' => response . ':' . $message,
            'cnt' => $cnt
        );
    }

    /**
     * rename the mailbox
     *
     * @param
     *            $old_name
     * @param
     *            $new_name
     * @param
     *            $delimiter
     */
    function mailboxRename($old_name, $new_name, $delimiter = '')
    {
        if ($old_name != $new_name) {
            if (empty($delimiter)) {
                $delimiter = $this->getMailboxDelimiter();
            }
            if (substr($old_name, - 1) == $delimiter) {
                $old_name = substr($old_name, 0, strlen($old_name) - 1);
                $new_name = substr($new_name, 0, strlen($new_name) - 1);
                $postfix = $delimiter;
            } else {
                $postfix = '';
            }
            if (ComDection::is_cn_code($old_name)) {
                $old_name = ComFunc::sm_mb_convert_encoding($old_name, 'UTF7-IMAP', 'UTF-8');
            }
            $this->mailboxUnsubscribe($old_name . $postfix);
            $data = $this->smimap_run_command('RENAME "' . $old_name . '" "' . $new_name . '"', true, $response, $message);
            $this->mailboxSubscribe($new_name . $postfix);
            return array(
                'msg' => response . ':' . $message
            );
        }
    }

    /**
     * Returns a list of all folders, subscribed or not
     *
     * @param
     *            $delimiter
     * @deprecated
     *
     */
    public function smimap_mailbox_list_all($delimiter)
    {
        $folder_prefix = '';
        $ssid = $this->smimap_session_id();
        $lsid = strlen($ssid);
        fputs($this->imapStream, $ssid . 'LIST' . '"' . $delimiter . '" ' . '"%"');
        $read_ary = $this->smimap_read_data($ssid, true, $response, $message);
        $g = 0;
        $phase = 'inbox';
        $fld_pre_length = strlen($folder_prefix);
        for ($i = 0, $cnt = count($read_ary); $i < $cnt; $i ++) {
            /* Another workaround for EIMS */
            if (isset($read_ary[$i + 1]) && preg_match('/^(\* [A-Z]+.*)\{[0-9]+\}([ \n\r\t]*)$/', $read_ary[$i], $regs)) {
                $i ++;
                $read_ary[$i] = $regs[1] . '"' . addslashes(trim($read_ary[$i])) . '"' . $regs[2];
            }
            if (substr($read_ary[$i], 0, $lsid) != $ssid) {
                /* Store the raw IMAP reply */
                $boxes[$g]['raw'] = $read_ary[$i];
                /* Count number of delimiters ($delimiter) in folder name */
                $mailbox = $this->find_mailbox_name($read_ary[$i]);
                $dm_count = substr_count($mailbox, $delimiter);
                if (substr($mailbox, - 1) == $delimiter) {
                    /* If name ends in delimiter - decrement count by one */
                    $dm_count --;
                }
                /* Format folder name, but only if it's a INBOX.* or has a parent. */
                $boxesallbyname[$mailbox] = $g;
                $parentfolder = $this->readMailboxParent($mailbox, $delimiter);
                /* @FIXME shouldn't use preg_match for simple string matching */
                if ((preg_match('|^inbox' . quotemeta($delimiter) . '|i', $mailbox)) || (preg_match('|^' . $folder_prefix . '|', $mailbox)) || (isset($boxesallbyname[$parentfolder]) && (strlen($parentfolder) > 0))) {
                    if ($dm_count) {
                        $boxes[$g]['formatted'] = str_repeat('&nbsp;&nbsp;', $dm_count);
                    } else {
                        $boxes[$g]['formatted'] = '';
                    }
                    $boxes[$g]['formatted'] .= ComMime::utf7_decode($this->readShortMailboxName($mailbox, $delimiter));
                } else {
                    $boxes[$g]['formatted'] = ComMime::utf7_decode($mailbox);
                }
                $boxes[$g]['unformatted-dm'] = $mailbox;
                if (substr($mailbox, - 1) == $delimiter) {
                    $mailbox = substr($mailbox, 0, strlen($mailbox) - 1);
                }
                $boxes[$g]['unformatted'] = $mailbox;
                $boxes[$g]['unformatted-disp'] = substr($mailbox, $fld_pre_length);
                $boxes[$g]['id'] = $g;
                /* Now lets get the flags for this mailbox */
                $read_mlbx = $read_ary[$i];
                $flags = substr($read_mlbx, strpos($read_mlbx, '(') + 1);
                $flags = substr($flags, 0, strpos($flags, ')'));
                $flags = str_replace('\\', '', $flags);
                $flags = trim(strtolower($flags));
                if ($flags) {
                    $boxes[$g]['flags'] = explode(' ', $flags);
                } else {
                    $boxes[$g]['flags'] = array();
                }
            }
            $g ++;
        }
        if (is_array($boxes)) {
            sort($boxes);
        }
        return $boxes;
    }

    /**
     * If $haystack is a full mailbox name and $needle is the mailbox
     * separator character, returns the last part of the mailbox name.
     *
     * @param
     *            string haystack full mailbox name to search
     * @param
     *            string needle the mailbox separator character
     * @return string the last part of the mailbox name
     */
    private function readShortMailboxName($haystack, $needle)
    {
        if ($needle == '') {
            $elem = $haystack;
        } else {
            $parts = explode($needle, $haystack);
            $elem = array_pop($parts);
            while ($elem == '' && count($parts)) {
                $elem = array_pop($parts);
            }
        }
        return ($elem);
    }

    /**
     * * If $haystack is a full mailbox name, and $needle is the mailbox
     * separator character, returns the second last part of the full
     * mailbox name (i.e.
     * the mailbox's parent mailbox)
     *
     * @param
     *            $haystack
     * @param
     *            $needle
     */
    private function readMailboxParent($haystack, $needle)
    {
        if ($needle == '') {
            $ret = '';
        } else {
            $parts = explode($needle, $haystack);
            $elem = array_pop($parts);
            while ($elem == '' && count($parts)) {
                $elem = array_pop($parts);
            }
            $ret = join($needle, $parts);
        }
        return ($ret);
    }

    /**
     * show all mailbox
     *
     * @param
     *            $delimiter
     * @param
     *            $force
     */
    public function mailboxList($force = false)
    {
        $default_sub_of_inbox = true;
        $list_special_folders_first = false;
        $inbox_subscribed = true;
        $delimiter = $this->getMailboxDelimiter();
        if (! $force) {
            // $lsub_args = "LSUB \"$folder_prefix\" \"*%\"";
            $lsub_args = 'LIST ' . '"" ' . '"%"';
        } else {
            $lsub_args = "LSUB \"$folder_prefix\" \"*\"";
        }
        /* LSUB array */
        $lsub_ary = $this->smimap_run_command($lsub_args, true, $response, $message);
        $sorted_lsub_ary = array();
        for ($i = 0, $cnt = count($lsub_ary); $i < $cnt; $i ++) {
            /*
             * Workaround for mailboxes returned as literal
             * Doesn't work if the mailbox name is multiple lines
             * (larger then fgets buffer)
             */
            if (isset($lsub_ary[$i + 1]) && substr($lsub_ary[$i], - 3) == "}\r\n") {
                if (preg_match('/^(\* [A-Z]+.*)\{[0-9]+\}([ \n\r\t]*)$/', $lsub_ary[$i], $regs)) {
                    $i ++;
                    $lsub_ary[$i] = $regs[1] . '"' . addslashes(trim($lsub_ary[$i])) . '"' . $regs[2];
                }
            }
            $temp_mailbox_name = $this->find_mailbox_name($lsub_ary[$i]);
            // echo $temp_mailbox_name.'<br/>';
            $sorted_lsub_ary[] = $temp_mailbox_name;
            if (! $inbox_subscribed && strtoupper($temp_mailbox_name) == 'INBOX') {
                $inbox_subscribed = true;
            }
        }
        $sorted_lsub_ary = array_unique($sorted_lsub_ary);
        /* natural sort mailboxes */
        if (isset($sorted_lsub_ary)) {
            $this->mailtree_sort($sorted_lsub_ary, $delimiter);
        }
        /*
         * The LSUB response doesn't provide us information about \Noselect
         * mail boxes. The LIST response does, that's why we need to do a LIST
         * call to retrieve the flags for the mailbox
         * Note: according RFC2060 an imap server may provide \NoSelect flags in the LSUB response.
         * in other words, we cannot rely on it.
         */
        $sorted_list_ary = array();
        for ($i = 0; $i < count($sorted_lsub_ary); $i ++) {
            if (substr($sorted_lsub_ary[$i], - 1) == $delimiter) {
                $mbx = substr($sorted_lsub_ary[$i], 0, strlen($sorted_lsub_ary[$i]) - 1);
            } else {
                $mbx = $sorted_lsub_ary[$i];
            }
            $command = 'LIST ' . '"" ' . '"' . $mbx . $delimiter . '%"';
            $read = $this->smimap_run_command($command, true, $response, $message);
            /* Another workaround for literals */
            if (isset($read[1]) && substr($read[1], - 3) == "}\r\n") {
                if (preg_match('/^(\* [A-Z]+.*)\{[0-9]+\}([ \n\r\t]*)$/', $read[0], $regs)) {
                    $read[0] = $regs[1] . '"' . addslashes(trim($read[1])) . '"' . $regs[2];
                }
            }
            if (isset($read[0])) {
                $sorted_list_ary[$i] = $read[0];
            } else {
                $sorted_list_ary[$i] = '';
            }
        }
        /*
         * Just in case they're not subscribed to their inbox,
         * we'll get it for them anyway
         */
        if (! $inbox_subscribed) {
            $inbox_ary = $this->smimap_run_command("LIST \"\" \"INBOX\"", true, $response, $message);
            /* Another workaround for literals */
            if (isset($inbox_ary[1]) && substr($inbox_ary[0], - 3) == "}\r\n") {
                if (preg_match('/^(\* [A-Z]+.*)\{[0-9]+\}([ \n\r\t]*)$/', $inbox_ary[0], $regs)) {
                    $inbox_ary[0] = $regs[1] . '"' . addslashes(trim($inbox_ary[1])) . '"' . $regs[2];
                }
            }
            $sorted_list_ary[] = $inbox_ary[0];
            $sorted_lsub_ary[] = $this->find_mailbox_name($inbox_ary[0]);
        }
        $boxesall = $this->mailboxParse($sorted_list_ary, $sorted_lsub_ary, $delimiter);
        /* Now, lets sort for special folders */
        $boxesnew = $used = array();
        /* Find INBOX */
        $cnt = count($boxesall);
        $used = array_pad($used, $cnt, false);
        for ($k = 0; $k < $cnt; ++ $k) {
            if (strtolower($boxesall[$k]['unformatted']) == 'inbox') {
                $boxesnew[] = $boxesall[$k];
                $used[$k] = true;
                break;
            }
        }
        /*
         * For systems where folders might be either under the INBOX or
         * at the top-level (Dovecot, hMailServer), INBOX subfolders have
         * to be added before special folders
         */
        if (! $default_sub_of_inbox) {
            for ($k = 0; $k < $cnt; ++ $k) {
                if (! $used[$k] && $this->isBoxBelow(strtolower($boxesall[$k]['unformatted']), 'inbox') && strtolower($boxesall[$k]['unformatted']) != 'inbox') {
                    $boxesnew[] = $boxesall[$k];
                    $used[$k] = true;
                }
            }
        }
        /* List special folders and their subfolders, if requested. */
        if ($list_special_folders_first) {
            for ($k = 0; $k < $cnt; ++ $k) {
                if (! $used[$k] && $this->isSpecialMailbox($boxesall[$k]['unformatted'])) {
                    $boxesnew[] = $boxesall[$k];
                    $used[$k] = true;
                }
            }
        }
        /* Find INBOX's children for systems where folders are ONLY under INBOX */
        if ($default_sub_of_inbox) {
            for ($k = 0; $k < $cnt; ++ $k) {
                if (! $used[$k] && $this->isBoxBelow(strtolower($boxesall[$k]['unformatted']), 'inbox') && strtolower($boxesall[$k]['unformatted']) != 'inbox') {
                    $boxesnew[] = $boxesall[$k];
                    $used[$k] = true;
                }
            }
        }
        /* Rest of the folders */
        for ($k = 0; $k < $cnt; $k ++) {
            if (! $used[$k]) {
                $boxesnew[] = $boxesall[$k];
            }
        }
        return $boxesnew;
    }

    /**
     * Defines special mailboxes: given a mailbox name, it checks if this is a
     * "special" one: INBOX, Trash, Sent or Draft.
     *
     * Since 1.2.5 function includes special_mailbox hook.
     *
     * Since 1.4.3 hook supports more than one plugin.
     *
     * //FIXME: make $subfolders_of_inbox_are_special a configuration setting in conf.pl and config.php
     * Since 1.4.22/1.5.2, the administrator can add
     * $subfolders_of_inbox_are_special = TRUE;
     * to config/config_local.php and all subfolders
     * of the INBOX will be treated as special.
     *
     * @param string $box
     *            mailbox name
     * @param boolean $include_subs
     *            (since 1.5.2 and 1.4.9) if true, subfolders of
     *            system folders are special. if false, subfolders are not special mailboxes
     *            unless they are tagged as special in 'special_mailbox' hook.
     * @return boolean
     * @since 1.2.3
     */
    private function isSpecialMailbox($box, $include_subs = true)
    {
        $subfolders_of_inbox_are_special = true;
        $ret = (($subfolders_of_inbox_are_special && $this->isInboxMailbox($box, $include_subs)) || (! $subfolders_of_inbox_are_special && strtolower($box) == 'inbox') || $this->isTrashMailbox($box, $include_subs) || $this->isSentMailbox($box, $include_subs) || $this->isDraftMailbox($box, $include_subs));
        return $ret;
    }

    /**
     * Detects if mailbox is the Inbox folder or subfolder of the Inbox
     *
     * @param string $box
     *            The mailbox name to test
     * @param boolean $include_subs
     *            If true, subfolders of system folders
     *            are special. If false, subfolders are
     *            not special mailboxes.
     *            
     * @return boolean Whether this is the Inbox or a child thereof.
     *        
     * @since 1.4.22
     */
    private function isInboxMailbox($box, $include_subs = TRUE)
    {
        return ((strtolower($box) == 'inbox') || ($include_subs && $this->isBoxBelow(strtolower($box), 'inbox')));
    }

    /**
     * Detects if mailbox is a Trash folder or subfolder of Trash
     *
     * @param string $box
     *            mailbox name
     * @param boolean $include_subs
     *            (since 1.5.2 and 1.4.9) if true, subfolders of
     *            system folders are special. if false, subfolders are not special mailboxes.
     * @return bool whether this is a Trash folder
     * @since 1.4.0
     */
    private function isTrashMailbox($box, $include_subs = true)
    {
        $trash_folder = 'Trash';
        return $trash_folder && ($box == $trash_folder || ($include_subs && $this->isBoxBelow($box, $trash_folder)));
    }

    /**
     * Detects if mailbox is a Sent folder or subfolder of Sent
     *
     * @param string $box
     *            mailbox name
     * @param boolean $include_subs
     *            (since 1.5.2 and 1.4.9) if true, subfolders of
     *            system folders are special. if false, subfolders are not special mailboxes.
     * @return bool whether this is a Sent folder
     * @since 1.4.0
     */
    private function isSentMailbox($box, $include_subs = true)
    {
        $sent_folder = 'Sent';
        return $sent_folder && ($box == $sent_folder || ($include_subs && $this->isBoxBelow($box, $sent_folder)));
    }

    /**
     * Detects if mailbox is a Drafts folder or subfolder of Drafts
     *
     * @param string $box
     *            mailbox name
     * @param boolean $include_subs
     *            (since 1.5.2 and 1.4.9) if true, subfolders of
     *            system folders are special. if false, subfolders are not special mailboxes.
     * @return bool whether this is a Draft folder
     * @since 1.4.0
     */
    private function isDraftMailbox($box, $include_subs = true)
    {
        $draft_folder = 'Drafts';
        return ($box == $draft_folder || ($include_subs && $this->isBoxBelow($box, $draft_folder)));
    }

    /**
     * Check if $subbox is below the specified $parentbox
     *
     * @param unknown_type $subbox            
     * @param unknown_type $parentbox            
     */
    private function isBoxBelow($subbox, $parentbox)
    {
        $delimiter = $this->getMailboxDelimiter();
        /*
         * Eliminate the obvious mismatch, where the
         * subfolder path is shorter than that of the potential parent
         */
        if (strlen($subbox) < strlen($parentbox)) {
            return false;
        }
        /* check for delimiter */
        if (substr($parentbox, - 1) != $delimiter) {
            $parentbox .= $delimiter;
        }
        if (substr($subbox, 0, strlen($parentbox)) == $parentbox) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Mailboxes with some chars (like -) can mess up the order, this fixes it
     *
     * @param
     *            $lsub
     */
    private function mailtree_sort(&$lsub, &$delimiter = '')
    {
        if (! is_array($lsub))
            return;
        foreach ($lsub as $index => $mailbox)
            $lsub[$index] = str_replace($delimiter, ' -#- ', $lsub[$index]);
        usort($lsub, array(
            'Smail\Util\ComFunc',
            'user_strcasecmp'
        ));
        foreach ($lsub as $index => $mailbox)
            $lsub[$index] = str_replace(' -#- ', $delimiter, $lsub[$index]);
    }

    /**
     * Formats a mailbox into parts for the $boxesall array
     *
     * The parts are:
     *
     * raw - Raw LIST/LSUB response from the IMAP server
     * formatted - nicely formatted folder name
     * unformatted - unformatted, but with delimiter at end removed
     * unformatted-dm - folder name as it appears in raw response
     * unformatted-disp - unformatted without $folder_prefix
     */
    private function mailboxParse(&$line, &$line_lsub, &$delimiter)
    {
        $folder_prefix = '[Gmail]';
        /* Process each folder line */
        for ($g = 0, $cnt = count($line); $g < $cnt; ++ $g) {
            /* Store the raw IMAP reply */
            if (isset($line[$g])) {
                $boxesall[$g]['raw'] = $line[$g];
            } else {
                $boxesall[$g]['raw'] = '';
            }
            /* Count number of delimiters ($delimiter) in folder name */
            $mailbox = $line_lsub[$g];
            $dm_count = substr_count($mailbox, $delimiter);
            if (substr($mailbox, - 1) == $delimiter) {
                /* If name ends in delimiter, decrement count by one */
                $dm_count --;
            }
            /* Format folder name, but only if it's a INBOX.* or has a parent. */
            $boxesallbyname[$mailbox] = $g;
            $parentfolder = $this->readMailboxParent($mailbox, $delimiter);
            if ((strtolower(substr($mailbox, 0, 5)) == "inbox") || (substr($mailbox, 0, strlen($folder_prefix)) == $folder_prefix) || (isset($boxesallbyname[$parentfolder]) && (strlen($parentfolder) > 0))) {
                $indent = $dm_count - (substr_count($folder_prefix, $delimiter));
                if ($indent > 0) {
                    $boxesall[$g]['formatted'] = str_repeat('&nbsp;&nbsp;', $indent);
                } else {
                    $boxesall[$g]['formatted'] = '';
                }
                $boxesall[$g]['formatted'] .= ComMime::utf7_decode($this->readShortMailboxName($mailbox, $delimiter));
            } else {
                $boxesall[$g]['formatted'] = ComMime::utf7_decode($mailbox);
            }
            $boxesall[$g]['unformatted-dm'] = $mailbox;
            if (substr($mailbox, - 1) == $delimiter) {
                $mailbox = substr($mailbox, 0, strlen($mailbox) - 1);
            }
            $boxesall[$g]['unformatted'] = $mailbox;
            if (substr($mailbox, 0, strlen($folder_prefix)) == $folder_prefix) {
                $mailbox = substr($mailbox, strlen($folder_prefix));
            }
            $boxesall[$g]['unformatted-disp'] = $mailbox;
            $boxesall[$g]['id'] = $g;
            
            $boxesall[$g]['flags'] = array();
            if (isset($line[$g])) {
                if (preg_match('/\(([^)]*)\)/', $line[$g], $regs)) {
                    $flags = trim(strtolower(str_replace('\\', '', $regs[1])));
                    if ($flags) {
                        $boxesall[$g]['flags'] = explode(' ', $flags);
                    }
                }
            }
        }
        return $boxesall;
    }

    /**
     * formate the name of mailbox
     *
     * @param string $mailbox            
     */
    private function find_mailbox_name($mailbox)
    {
        if (preg_match('/\*.+\"([^\r\n\"]*)\"[\s\r\n]*$/', $mailbox, $regs))
            return $regs[1];
        if (preg_match('/ *"([^\r\n"]*)"[ \r\n]*$/', $mailbox, $regs))
            return $regs[1];
        preg_match('/ *([^ \r\n"]*)[ \r\n]*$/', $mailbox, $regs);
        return $regs[1];
    }

    /**
     * Returns the delimeter between mailboxes
     *
     * @example INBOX/Test, or INBOX.Test
     * @return string $smimap_delimiter
     */
    public function getMailboxDelimiter()
    {
        /* Do some caching here */
        if ($this->capability('NAMESPACE')) {
            /*
             * According to something that I can't find, this is supposed to work on all systems
             * OS: This won't work in Courier IMAP.
             * OS: According to rfc2342 response from NAMESPACE command is:
             * OS: * NAMESPACE (PERSONAL NAMESPACES) (OTHER_USERS NAMESPACE) (SHARED NAMESPACES)
             * OS: We want to lookup all personal NAMESPACES...
             */
            $read = $this->smimap_run_command('NAMESPACE', true, $a, $b);
            if (preg_match('/\* NAMESPACE +(\( *\(.+\) *\)|NIL) +(\( *\(.+\) *\)|NIL) +(\( *\(.+\) *\)|NIL)/i', $read[0], $data)) {
                if (preg_match('/^\( *\((.*)\) *\)/', $data[1], $data2)) {
                    $pn = $data2[1];
                }
                $pna = explode(')(', $pn);
                while (list ($k, $v) = each($pna)) {
                    $lst = explode('"', $v);
                    if (isset($lst[3])) {
                        $pn[$lst[1]] = $lst[3];
                    } else {
                        $pn[$lst[1]] = '';
                    }
                }
            }
            $delimiter = $pn[0];
        } else {
            fputs($this->imapStream, ". LIST \"INBOX\" \"\"\r\n");
            $read = $this->smimap_read_data('.', true, $a, $b);
            $quote_position = strpos($read[0], '"');
            $delimiter = substr($read[0], $quote_position + 1, 1);
        }
        return $delimiter;
    }

    /**
     * Gets the number of messages in the current mailbox.
     *
     * @param string $mailbox            
     * @return int number Message
     */
    public function getEmailCount($mailbox)
    {
        $read_ary = $this->smimap_run_command("EXAMINE \"$mailbox\"", false, $result, $message);
        for ($i = 0; $i < count($read_ary); $i ++) {
            if (preg_match('/[^ ]+ +([^ ]+) +EXISTS/', $read_ary[$i], $regs)) {
                return $regs[1];
            }
        }
        throw new \Exception("Couldn't get number of messages in $mailbox!");
        // return false; // "BUG! Couldn't get number of messages in $mailbox!";
    }

    /**
     * Returns the number of unseen messages in this folder.
     *
     * @param string $mailbox            
     */
    public function getUnSeenEmailCount($mailbox)
    {
        $read_ary = $this->smimap_run_command("STATUS \"$mailbox\" (UNSEEN)", false, $result, $message);
        $i = 0;
        $regs = array(
            false,
            false
        );
        while (isset($read_ary[$i])) {
            if (preg_match('/UNSEEN\s+([0-9]+)/i', $read_ary[$i], $regs)) {
                break;
            }
            $i ++;
        }
        return $regs[1];
    }

    /**
     * Returns the number of unseen/total/recent messages in this folder
     *
     * @param string $mailbox            
     * @return ArrayObject MESSAGES UNSEEN RECENT
     */
    public function statusEmail($mailbox)
    {
        $read_ary = $this->smimap_run_command("STATUS \"$mailbox\" (MESSAGES UNSEEN RECENT)", false, $result, $message);
        $i = 0;
        $messages = $unseen = $recent = false;
        $regs = array(
            false,
            false
        );
        while (isset($read_ary[$i])) {
            if (preg_match('/UNSEEN\s+([0-9]+)/i', $read_ary[$i], $regs)) {
                $unseen = $regs[1];
            }
            if (preg_match('/MESSAGES\s+([0-9]+)/i', $read_ary[$i], $regs)) {
                $messages = $regs[1];
            }
            if (preg_match('/RECENT\s+([0-9]+)/i', $read_ary[$i], $regs)) {
                $recent = $regs[1];
            }
            $i ++;
        }
        return array(
            'MESSAGES' => $messages,
            'UNSEEN' => $unseen,
            'RECENT' => $recent
        );
    }

    /**
     * 邮件检索
     *
     * @param string $search_what            
     */
    function searchEmail($search_what)
    {
        $allow_charset_search = TRUE;
        $uid_support = TRUE;
        $search_where = 'TEXT';
        
        $multi_search = array();
        $search_what = trim($search_what);
        $search_what = preg_replace('/[ ]{2,}/', ' ', $search_what);
        $multi_search = explode(' ', $search_what);
        $search_string = '';
        foreach ($multi_search as $string) {
            $search_string .= $search_where . ' "' . str_replace(array(
                '\\',
                '"'
            ), array(
                '\\\\',
                '\\"'
            ), $string) . '" ';
        }
        $search_string = trim($search_string);
        /* now use $search_string in the imap search */
        if ($allow_charset_search) {
            $ss = "SEARCH CHARSET UTF-8" . " ALL $search_string";
        } else {
            $ss = "SEARCH ALL $search_string";
        }
        /* read data back from IMAP */
        $readin = $this->smimap_run_command($ss, false, $result, $message, $uid_support);
        /* try US-ASCII charset if search fails */
        if (strtolower($result) == 'no') {
            $ss = "SEARCH CHARSET \"US-ASCII\" ALL $search_string";
            if (empty($search_lit)) {
                $readin = $this->smimap_run_command($ss, false, $result, $message, $uid_support);
                if (strtolower($result) == 'no') {
                    $ss = "SEARCH CHARSET \"GB18030\" ALL $search_string";
                    $readin = $this->smimap_run_command($ss, false, $result, $message, $uid_support);
                    echo $message;
                }
            } else {
                $search_lit['command'] = $ss;
                $readin = $this->smimap_run_literal_command($search_lit, false, $result, $message, $uid_support);
            }
        }
        /* Keep going till we find the SEARCH response */
        $messagelist = [];
        foreach ($readin as $readin_part) {
            /* Check to see if a SEARCH response was received */
            if (substr($readin_part, 0, 9) == '* SEARCH ') {
                $messagelist = preg_split("/ /", substr($readin_part, 9));
            } else 
                if (isset($errors)) {
                    $errors = $errors . $readin_part;
                } else {
                    $errors = $readin_part;
                }
        }
        /* If nothing is found * SEARCH should be the first error else echo errors */
        if (isset($errors)) {
            if (strstr($errors, '* SEARCH')) {
                return array();
            }
        }
        $num_msgs = count($messagelist);
        for ($q = 0; $q < $num_msgs; $q ++) {
            $id[$q] = trim($messagelist[$q]);
        }
        return $id;
    }

    /**
     * Saves a message to a given folder -- used for saving sent messages
     *
     * @param string $sent_folder            
     * @param int $length            
     */
    public function appendEmail($sent_folder, $length)
    {
        // fputs($this->imapStream, $this->smimap_session_id() . " APPEND \"$sent_folder\" (\\Seen) {" . $length . "}\r\n");
        // $tmp = fgets($this->imapStream, 1024);
        $this->smimap_run_command("APPEND \"$sent_folder\" (\\Seen) {" . $length . "}\r\n", true, $tmp, $msg);
        $this->append_checkresponse($tmp, $sent_folder);
    }

    /**
     *
     * @param string $folder            
     */
    public function appendEmailDone($folder = '')
    {
        // fputs($this->imapStream, "\r\n");
        // $tmp = fgets($this->imapStream, 1024);
        $this->smimap_run_command("\r\n", true, $tmp, $msg);
        $this->append_checkresponse($tmp, $folder);
    }

    /**
     * 获取append命令的返回状态
     *
     * @param
     *            $response
     * @param
     *            $folder
     */
    private function append_checkresponse($response, $folder)
    {
        if (preg_match("/(.*)(BAD|NO)(.*)$/", $response, $regs)) {
            $reason = $regs[3];
            if ($regs[2] == 'NO') {
                $string = "ERROR: Could not append message to" . " $folder." . "Server responded:" . ' ' . $reason;
                if (preg_match("/(.*)(quota)(.*)$/i", $reason, $regs)) {
                    $string .= "Solution:" . ' ' . "Remove unneccessary messages from your folders. Start with your Trash folder.";
                }
            } else {
                $string = "ERROR: Bad or malformed request." . "Server responded:" . ' ' . $reason;
            }
            throw new \Exception($string);
        }
    }

    /**
     * get the detail of the email
     *
     * @param
     *            $id
     * @param
     *            $mailbox
     */
    public function getEmailById($id, $mailbox)
    {
        $uid_support = true;
        // type cast to int to prohibit 1:* msgs sets
        $id = (int) $id;
        $flags = array();
        $read = $this->smimap_run_command("FETCH $id (FLAGS BODYSTRUCTURE)", true, $response, $message, $uid_support);
        if ($read) {
            if (preg_match('/.+FLAGS\s\((.*)\)\s/AUi', $read[0], $regs)) {
                if (trim($regs[1])) {
                    $flags = preg_split('/ /', $regs[1], - 1, PREG_SPLIT_NO_EMPTY);
                }
            }
        } else {
            throw new \Exception("The server couldn't find the message you requested." . "Most probably your message list was out of date and the message has been moved away or deleted (perhaps by another program accessing the same mailbox).");
        }
        $bodystructure = implode('', $read);
        $msg = ComMime::mime_structure($bodystructure, $flags);
        $read = $this->smimap_run_command("FETCH $id BODY[HEADER]", true, $response, $message, $uid_support);
        $rfc822_header = new Rfc822Header();
        $rfc822_header->parseHeader($read);
        $msg->rfc822_header = $rfc822_header;
        $this->parse_message_entities($msg, $id);
        $email = array();
        $header = $msg->rfc822_header;
        $env = array();
        $env['Subject'] = ComMime::decodeHeader($header->subject);
        if (empty($env['Subject'])) {
            $env['Subject'] = '无主题';
        }
        $from_name = $header->getAddr_s('from');
        if (! $from_name) {
            $from_name = $header->getAddr_s('sender');
            if (! $from_name) {
                $from_name = '未知发件人';
            }
        }
        while (strpos($from_name, '?=') > 0) {
            $from_name = ComMime::decodeHeader($from_name);
        }
        $env['From'] = $from_name;
        date_default_timezone_set('PRC');
        $env['Date'] = date("Y年m月d日H:i", $header->date);
        $env['To'] = $this->formatRecipientString($header->to);
        $env['Cc'] = $this->formatRecipientString($header->cc);
        $env['Bcc'] = $this->formatRecipientString($header->bcc);
        $env['Priority'] = htmlspecialchars(ComFunc::getPriorityStr($header->priority));
        $env['Mailer'] = ComMime::decodeHeader($header->xmailer);
        $email['header'] = $env;
        $ent_ar = $msg->findDisplayEntity(array());
        $cnt = count($ent_ar);
        for ($i = 0; $i < $cnt; $i ++) {
            $messagebody .= $this->formatBody($msg, $color, $wrap_at, $ent_ar[$i], $id, $mailbox);
            if ($i != $cnt - 1) {
                $messagebody .= '<hr noshade size=1>';
            }
        }
        $email['body'] = $messagebody;
        if ($msg->type1 == 'mixed') {
            $attach = ComMime::formatAttachments($msg, $ent_ar, $mailbox, $id);
            $email['attach'] = $attach;
        }
        return $email;
    }

    /**
     * 解析邮件体
     *
     * @param mixed $msg            
     * @param string $id            
     */
    private function parse_message_entities(&$msg, $id)
    {
        $uid_support = true;
        if (! empty($msg->entities))
            foreach ($msg->entities as $i => $entity) {
                if (is_object($entity) && strtolower(get_class($entity)) == 'message') {
                    if (! empty($entity->rfc822_header)) {
                        $read = $this->smimap_run_command("FETCH $id BODY[" . $entity->entity_id . ".HEADER]", true, $response, $message, $uid_support);
                        $rfc822_header = new Rfc822Header();
                        $rfc822_header->parseHeader($read);
                        $msg->entities[$i]->rfc822_header = $rfc822_header;
                    }
                    $this->parse_message_entities($msg->entities[$i], $id);
                }
            }
    }

    /**
     * 格式化cc bcc to
     *
     * @param array $recipients            
     */
    private function formatRecipientString($recipients)
    {
        $string = '';
        if ((is_array($recipients)) && (isset($recipients[0]))) {
            foreach ($recipients as $r) {
                $add = ComMime::decodeHeader($r->getAddress(true));
                $string .= $add . ';';
            }
        }
        return $string;
    }

    /**
     * copy the mail to the destination folder
     *
     * NOTE: Verions of this function BEFORE SquirrelMail 1.4.18
     * actually *moved* messages instead of copying them
     *
     * @param mixed $id
     *            A string or array of messages to copy
     * @param string $mailbox
     *            The mailbox to copy messages to
     *            
     * @return bool Returns true on successful copy, false on failure
     *        
     */
    public function copyEmailById($id, $mailbox)
    {
        $msgs_id = $this->smimap_message_list_squisher($id);
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        $read = $this->smimap_run_command("COPY $msgs_id \"$mailbox\"", true, $response, $message, true);
        return $response;
    }

    /**
     * Moves a set of messages ($id) to another folder
     *
     * @param mixed $id
     *            A string or array of messages to copy
     * @param string $mailbox
     *            The destination mailbox
     * @param bool $handle_errors
     *            Show error messages in case of a NO, BAD, or BYE response
     *            
     * @return bool If move completed without error.
     *        
     * @since 1.4.18
     *       
     */
    public function moveEmailById($id, $mailbox)
    {
        if (ComDection::is_cn_code($mailbox)) {
            $mailbox = ComFunc::sm_mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        }
        if ($this->isMailboxExists($mailbox)) {
            $response = $this->copyEmailById($id, $mailbox);
            if ($response == 'OK') {
                $response = $this->flagEmailById($id, '\\Deleted', true, true);
                $this->mailboxExpunge($mailbox, $id);
                return $response;
            } else {
                throw new \Exception($response);
            }
        }
    }

    /**
     * add or remove the flag of the mail
     *
     * @param mixed $id
     *            A string or array of messages to add or remove flags
     * @param string $flag
     *            flags type
     * @param boolean $set
     *            true or false
     * @param string $handle_errors
     *            smimap_toggle_flag
     *            error message
     */
    public function flagEmailById($id, $flag, $set = false, $handle_errors = true)
    {
        $uid_support = TRUE;
        $msgs_id = $this->smimap_message_list_squisher($id);
        $set_string = $set ? '+' : '-';
        $read = $this->smimap_run_command("STORE $msgs_id " . $set_string . "FLAGS ($flag)", $handle_errors, $response, $message, $uid_support);
        return $response;
    }

    /**
     * 获取邮件的头信息
     *
     * @param unknown_type $start_msg            
     * @param unknown_type $show_num            
     * @param unknown_type $mbxresponse            
     */
    public function getEmailHeaders($mbxresponse, $start_msg = 1, $show_num = 6)
    {
        $msgs = array();
        if ($mbxresponse['EXISTS'] >= 1) {
            $num_msgs = $mbxresponse['EXISTS'];
            $id = $this->getMailIdsByMailbox($mbxresponse); // 需要将ID缓存
            /* if it's not sorted */
            if ($start_msg + ($show_num - 1) < $num_msgs) {
                $end_msg = $start_msg + ($show_num - 1);
            } else {
                $end_msg = $num_msgs;
            }
            if ($end_msg < $start_msg) {
                $start_msg = $start_msg - $show_num;
                if ($start_msg < 1) {
                    $start_msg = 1;
                }
            }
            $id = array_slice(array_reverse($id), ($start_msg - 1), $show_num);
            $end = $start_msg + $show_num - 1;
            if ($num_msgs < $show_num) {
                $end_loop = $num_msgs;
            } else 
                if ($end > $num_msgs) {
                    $end_loop = $num_msgs - $start_msg + 1;
                } else {
                    $end_loop = $show_num;
                }
            $msgs = $this->smimap_get_mail_header_list($id, $end_loop);
        }
        return $msgs;
    }

    /**
     * 获取头信息
     *
     * @param array $msg_list            
     * @param int $show_num
     *            smimap_get_mail_header_list
     */
    private function smimap_get_mail_header_list($id, $show_num = false)
    {
        $allow_server_sort = true;
        $uid_support = true;
        $maxmsg = sizeof($id);
        if ($show_num != '999999') {
            $msgs_str = $this->smimap_message_list_squisher($id);
        } else {
            $msgs_str = '1:*';
        }
        $messages = array();
        $read_list = array();
        
        for ($i = 0; $i < sizeof($msg_list); $i ++) {
            $messages["$msg_list[$i]"] = array();
        }
        $internaldate = false;
        if ($internaldate) {
            $query = "FETCH $msgs_str (FLAGS UID RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (Date To Cc From Subject X-Priority Importance Priority Content-Type)])";
        } else {
            $query = "FETCH $msgs_str (FLAGS UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS (Date To Cc From Subject X-Priority Importance Priority Content-Type)])";
        }
        $read_list = $this->smimap_run_command_list($query, true, $response, $message, $uid_support);
        $i = 0;
        foreach ($read_list as $r) {
            /* initialize/reset vars */
            $subject = '';
            $from = '';
            $priority = 0;
            $messageid = '<>';
            $type = array(
                '',
                ''
            );
            $cc = $to = $inrepto = '';
            $size = 0;
            $flag_seen = $flag_answered = $flag_deleted = $flag_flagged = false;
            $read = implode('', $r);
            /* extract the message id */
            $i_space = strpos($read, ' ', 2);
            $id = substr($read, 2, $i_space - 2);
            $fetch = substr($read, $i_space + 1, 5);
            if (! is_numeric($id) && $fetch !== 'FETCH') {
                $string = "ERROR: Could not complete request." . "Unknown response from IMAP server:" . ' 1.' . $read;
                throw new \Exception($string);
            }
            $i = strpos($read, '(', $i_space + 5);
            $read = substr($read, $i + 1);
            $i_len = strlen($read);
            $i = 0;
            while ($i < $i_len && $i !== false) {
                /* get argument */
                $read = trim(substr($read, $i));
                $i_len = strlen($read);
                $i = strpos($read, ' ');
                $arg = substr($read, 0, $i);
                ++ $i;
                switch ($arg) {
                    case 'UID':
                        $i_pos = strpos($read, ' ', $i);
                        if (! $i_pos) {
                            $i_pos = strpos($read, ')', $i);
                        }
                        if ($i_pos) {
                            $unique_id = substr($read, $i, $i_pos - $i);
                            $i = $i_pos + 1;
                        } else {
                            break 3;
                        }
                        break;
                    case 'FLAGS':
                        $flags = ComFunc::parseArray($read, $i);
                        if (! $flags)
                            break 3;
                        foreach ($flags as $flag) {
                            $flag = strtolower($flag);
                            switch ($flag) {
                                case '\\seen':
                                    $flag_seen = true;
                                    break;
                                case '\\answered':
                                    $flag_answered = true;
                                    break;
                                case '\\deleted':
                                    $flag_deleted = true;
                                    break;
                                case '\\flagged':
                                    $flag_flagged = true;
                                    break;
                                default:
                                    break;
                            }
                        }
                        break;
                    case 'RFC822.SIZE':
                        $i_pos = strpos($read, ' ', $i);
                        if (! $i_pos) {
                            $i_pos = strpos($read, ')', $i);
                        }
                        if ($i_pos) {
                            $size = substr($read, $i, $i_pos - $i);
                            $i = $i_pos + 1;
                        } else {
                            break 3;
                        }
                        break;
                    case 'INTERNALDATE':
                        $internal_date = ComFunc::parseString($read, $i);
                        break;
                    case 'BODY.PEEK[HEADER.FIELDS':
                    case 'BODY[HEADER.FIELDS':
                        $i = strpos($read, '{', $i);
                        $header = ComFunc::parseString($read, $i);
                        if ($header === false)
                            break 2;
                            /* First we replace all \r\n by \n, and unfold the header */
                        $hdr = trim(str_replace(array(
                            "\r\n",
                            "\n\t",
                            "\n "
                        ), array(
                            "\n",
                            ' ',
                            ' '
                        ), $header));
                        /* Now we can make a new header array with */
                        /* each element representing a header line */
                        $hdr = explode("\n", $hdr);
                        foreach ($hdr as $line) {
                            $pos = strpos($line, ':');
                            if ($pos > 0) {
                                $field = strtolower(substr($line, 0, $pos));
                                if (! strstr($field, ' ')) { /* valid field */
                                    $value = trim(substr($line, $pos + 1));
                                    switch ($field) {
                                        case 'to':
                                            $to = $value;
                                            break;
                                        case 'cc':
                                            $cc = $value;
                                            break;
                                        case 'from':
                                            $from = $value;
                                            break;
                                        case 'date':
                                            $date = $value;
                                            break;
                                        case 'x-priority':
                                        case 'importance':
                                        case 'priority':
                                            $priority = $this->parsePriority($value);
                                            break;
                                        case 'subject':
                                            $subject = $value;
                                            if ($subject == "") {
                                                $subject = '无主题';
                                            }
                                            break;
                                        case 'content-type':
                                            $type = strtolower($value);
                                            if ($pos = strpos($type, ";")) {
                                                $type = substr($type, 0, $pos);
                                            }
                                            $type = explode("/", $type);
                                            if (empty($type[0])) {
                                                $type[0] = 'text';
                                            }
                                            if (empty($type[1])) {
                                                $type[1] = 'plain';
                                            }
                                            break;
                                        default:
                                            break;
                                    }
                                }
                            }
                        }
                        break;
                    default:
                        ++ $i;
                        break;
                }
            }
            if (isset($date) || isset($internal_date)) {
                if (isset($internal_date)) {
                    $internal_date = str_replace('  ', ' ', $internal_date);
                    $tmpinternal_date = explode(' ', trim($internal_date));
                    if (! isset($date)) {
                        $date = $internal_date;
                        $tmpdate = $tmpinternal_date;
                    }
                }
                if (isset($date)) {
                    $date = str_replace('  ', ' ', $date);
                    $tmpdate = explode(' ', trim($date));
                    if (! isset($internal_date)) {
                        $internal_date = $date;
                        $tmpinternal_date = $tmpdate;
                    }
                }
            } else {
                $internal_date = $tmpinternal_date = $tmpdate = $date = array();
            }
            if ($uid_support) {
                $msgi = "$unique_id";
                $messages[$msgi]['ID'] = $unique_id;
            } else {
                $msgi = "$id";
                $messages[$msgi]['ID'] = $id;
            }
            $messages[$msgi]['RECEIVED_TIME_STAMP'] = ComDate::getTimeStamp($tmpinternal_date);
            $messages[$msgi]['TIME_STAMP'] = ComDate::getTimeStamp($tmpdate);
            date_default_timezone_set('PRC');
            $read_time = date("Y年m月d日H:i", ComDate::getTimeStamp($tmpdate));
            $messages[$msgi]['READABLE_TIME'] = $read_time;
            $from = ComFunc::parseAddress($from);
            if ($from[0][1]) {
                $from = ComMime::decodeHeader($from[0][1], true, false);
            } else {
                $from = $from[0][0];
            }
            $messages[$msgi]['FROM'] = $from;
            $subject = ComMime::decodeHeader($subject, true, false);
            $messages[$msgi]['SUBJECT'] = $subject;
            $to = ComFunc::parseAddress($to);
            if ($to[0][1]) {
                $to = ComMime::decodeHeader($to[0][1], true, false);
            } else {
                $to = $to[0][0];
            }
            $messages[$msgi]['TO'] = $to;
            $messages[$msgi]['PRIORITY'] = $priority;
            $cc = ComFunc::parseAddress($cc);
            if ($cc[0][1]) {
                $cc = ComMime::decodeHeader($cc[0][1], true, false);
            } else {
                $cc = $cc[0][0];
            }
            $messages[$msgi]['CC'] = $cc;
            $messages[$msgi]['SIZE'] = $size;
            $messages[$msgi]['TYPE0'] = $type[0];
            $messages[$msgi]['TYPE1'] = $type[1];
            $messages[$msgi]['FLAG_DELETED'] = $flag_deleted;
            $messages[$msgi]['FLAG_ANSWERED'] = $flag_answered;
            $messages[$msgi]['FLAG_SEEN'] = $flag_seen;
            $messages[$msgi]['FLAG_FLAGGED'] = $flag_flagged;
            
            /* non server sort stuff */
            if (! $allow_server_sort) {
                $messages[$msgi]['FROM-SORT'] = $from;
                $subject_sort = strtolower($subject);
                if (preg_match("/^(?:(?:vedr|sv|re|aw|fw|fwd|\[\w\]):\s*)*\s*(.*)$/si", $subject_sort, $matches)) {
                    $messages[$msgi]['SUBJECT-SORT'] = $matches[1];
                } else {
                    $messages[$msgi]['SUBJECT-SORT'] = $subject_sort;
                }
                $messages[$msgi]['TO-SORT'] = $to;
            }
            ++ $msgi;
        }
        $new_messages = array_reverse($messages);
        return $new_messages;
    }

    /**
     * 获取邮件的优先级
     *
     * @param string $sValue            
     */
    private function parsePriority($sValue)
    {
        $aValue = preg_split('/\s/', trim($sValue));
        $value = strtolower(array_shift($aValue));
        if (is_numeric($value)) {
            return $value;
        }
        if ($value == 'urgent' || $value == 'high') {
            return 1;
        } elseif ($value == 'non-urgent' || $value == 'low') {
            return 5;
        }
        return 3;
    }

    /**
     * Delete one or more message(s) and move it/them to trash or expunge the folder
     *
     * @param
     *            $from_folder
     * @param
     *            $id
     * @param
     *            $direct_del
     */
    public function deleteEmailById($id, $from_folder, $direct_del = false)
    {
        $uid_support = true;
        if (! $direct_del) {
            $trash_folder = $this->fetch_inner_mailbox('trash');
            if ($from_folder == $trash_folder || empty($trash_folder)) {
                throw new \Exception('illegal exception');
            }
            if (($this->isMailboxExists($trash_folder))) {
                $msgs_id = $this->smimap_message_list_squisher($id);
                /**
                 * turn off internal error handling (third argument = false) and
                 * ignore copy to trash errors (allows to delete messages when overquota)
                 */
                $read = $this->smimap_run_command("COPY $msgs_id \"$trash_folder\"", false, $response, $message, $uid_support);
                $read = $this->smimap_run_command("STORE $msgs_id +FLAGS (\\Deleted)", true, $response, $message, $uid_support);
            }
        } else {
            $msgs_id = $this->smimap_message_list_squisher($id);
            $read = $this->smimap_run_command("STORE $msgs_id +FLAGS (\\Deleted)", true, $response, $message, $uid_support);
        }
        return $response;
    }

    /**
     * fetch innser mibox
     *
     * @param $type sent
     *            trash
     */
    private function fetch_inner_mailbox($type = 'trash')
    {
        $boxes = $this->smimap_mailbox_list();
        switch (strtolower($type)) {
            case 'trash':
                $box_name1 = '已删除';
                $box_name2 = 'trash';
                break;
            case 'sent':
                $box_name1 = '已发送';
                $box_name2 = 'sent';
                break;
        }
        $folder = '';
        foreach ($boxes as $box) {
            if ($box['formatted'] == $box_name1 || strtolower($box['formatted']) == $box_name2) {
                $folder = $box['unformatted'];
                break;
            }
        }
        return $folder;
    }

    /**
     * Returns the references header lines
     *
     * @param string $id            
     * @return array $responses
     */
    public function getReferenceHeadersById($id)
    {
        $uid_support = true;
        $responses = array();
        $responses = $this->smimap_run_command("FETCH $id BODY[HEADER.FIELDS (References)]", true, $response, $message, $uid_support);
        if (! preg_match("/^\* ([0-9]+) FETCH/i", $responses[0][0], $regs)) {
            $responses = array();
        }
        return $responses;
    }

    /**
     * Get sort order from server and return it as the $id array for mailbox_display
     *
     * @param string $mailbox            
     * @param $sort 排序类型
     *            DATE FROM SUBJECT SIZE ARRIVAL
     * @return array $server_sort_array
     */
    public function serverSort($mailbox, $sort = '')
    {
        $mbxresponse = $this->mailboxSelect($mailbox);
        $uid_support = $mbxresponse['UIDVALIDITY'];
        $server_sort_array = array();
        $sort_test = array();
        // gmail does not support sorting I guess, so it always should have default sort
        if ($this->imap_server_type == 'gmail') {
            if ($uid_support) {
                if (isset($mbxresponse['UIDNEXT']) && $mbxresponse['UIDNEXT']) {
                    $uidnext = $mbxresponse['UIDNEXT'] - 1;
                } else {
                    $uidnext = '*';
                }
                $query = "SEARCH UID 1:$uidnext";
                $uids = $this->smimap_run_command_list($query, true, $response, $message, true);
                if (isset($uids[0])) {
                    for ($i = 0, $iCnt = count($uids); $i < $iCnt; ++ $i) {
                        for ($j = 0, $jCnt = count($uids[$i]); $j < $jCnt; ++ $j) {
                            if (preg_match("/^\* SEARCH (.+)$/", $uids[$i][$j], $regs)) {
                                $server_sort_array += preg_split("/ /", trim($regs[1]));
                            }
                        }
                    }
                }
                if (! preg_match("/OK/", $response)) {
                    throw new \Exception($response);
                }
            } else {
                $qty = $mbxresponse['EXISTS'];
                $server_sort_array = range(1, $qty);
            }
            $server_sort_array = array_reverse($server_sort_array);
            return $server_sort_array;
        }
        $sort_on = array(
            'DATE',
            'FROM',
            'SUBJECT',
            'SIZE',
            'ARRIVAL'
        );
        if (! empty($sort_on[$sort])) {
            $query = "SORT ($sort_on[$sort]) " . 'UTF-8' . ' ALL';
            $sort_test = $this->smimap_run_command($query, true, $response, $message, $uid_support);
        }
        if (isset($sort_test[0])) {
            for ($i = 0, $iCnt = count($sort_test); $i < $iCnt; ++ $i) {
                for ($j = 0, $jCnt = count($sort_test[$i]); $j < $jCnt; ++ $j) {
                    if (preg_match("/^\* SORT (.+)$/", $sort_test[$i][$j], $regs)) {
                        $server_sort_array += preg_split("/ /", trim($regs[1]));
                    }
                }
            }
        }
        $server_sort_array = array_reverse($server_sort_array);
        if (! preg_match("/OK/", $response)) {
            throw new \Exception($response);
        }
        return $server_sort_array;
    }

    /**
     * Get sort order from local and return it as the $id array for mailbox_display
     *
     * @param array $mbxresponse
     *            smimap_get_php_sort_order
     */
    public function getMailIdsByMailbox($mailbox)
    {
        $mbxresponse = $this->mailboxSelect($mailbox);
        $uid_support = $mbxresponse['UIDVALIDITY'];
        $id_arr = array();
        if ($uid_support) {
            $uidnext = isset($mbxresponse['UIDNEXT']) ? $mbxresponse['UIDNEXT'] - 1 : '*';
            $query = "SEARCH UID 1:$uidnext";
            $uids = $this->smimap_run_command($query, true, $response, $message, true);
            if (isset($uids[0])) {
                // EIMS workaround. EIMS returns the result as multiple untagged SEARCH responses
                foreach ($uids as $line) {
                    if (preg_match("/^\* SEARCH (.+)$/", $line, $regs)) {
                        $id_arr += preg_split("/ /", trim($regs[1]));
                    }
                }
            }
            if (! preg_match("/OK/", $response)) {
                throw new \Exception($response);
            }
        } else {
            $qty = $mbxresponse['EXISTS'];
            $id_arr = range(1, $qty);
        }
        return $id_arr;
    }

    /**
     * This returns a parsed string called $body.
     * That string can then
     * be displayed as the actual message in the HTML. It contains
     * everything needed, including HTML Tags, Attachments at the
     * bottom, etc.
     */
    public function formatBody($message, $color, $wrap_at, $ent_num, $id)
    {
        $body = '';
        $body_message = $message->getEntity($ent_num);
        if (($body_message->header->type0 == 'text') || ($body_message->header->type0 == 'rfc822')) {
            $body = $this->mime_fetch_body($id, $ent_num);
            $body = ComMime::decodeBody($body, $body_message->header->encoding);
            /*
             * If there are other types that shouldn't be formatted, add
             * them here.
             */
            $show_html_default = 1;
            if ($body_message->header->type1 == 'html') {
                if ($show_html_default != 1) {
                    $entity_conv = array(
                        '&nbsp;' => ' ',
                        '<p>' => "\n",
                        '<P>' => "\n",
                        '<br>' => "\n",
                        '<BR>' => "\n",
                        '<br />' => "\n",
                        '<BR />' => "\n",
                        '&gt;' => '>',
                        '&lt;' => '<'
                    );
                    $body = strtr($body, $entity_conv);
                    $body = strip_tags($body);
                    $body = trim($body);
                    ComMime::translateText($body, $wrap_at, $body_message->header->getParameter('charset'));
                } else {
                    $charset = $body_message->header->getParameter('charset');
                    if (! empty($charset)) {
                        $charset = strtolower($charset);
                        if ($charset == 'gbk' || $charset == 'gb2312' || $charset == 'gb18030') {
                            $body = iconv($charset, 'utf-8//IGNORE', $body);
                        }
                        $body = ComMime::magicHTML($body, $id, $message);
                    }
                }
            } else {
                $charset = $body_message->header->getParameter('charset');
                ComMime::translateText($body, $wrap_at, $charset);
            }
        }
        return $body;
    }

    /*
     * This starts the parsing of a particular structure. It is called recursively,
     * so it can be passed different structures. It returns an object of type
     * $message.
     * First, it checks to see if it is a multipart message. If it is, then it
     * handles that as it sees is necessary. If it is just a regular entity,
     * then it parses it and adds the necessary header information (by calling out
     * to mime_get_elements()
     */
    public function mime_fetch_body($id, $ent_id = 1, $fetch_size = 0)
    {
        $uid_support = true;
        /*
         * Do a bit of error correction. If we couldn't find the entity id, just guess
         * that it is the first one. That is usually the case anyway.
         */
        if (! $ent_id) {
            $cmd = "FETCH $id BODY[]";
        } else {
            $cmd = "FETCH $id BODY[$ent_id]";
        }
        
        if ($fetch_size != 0)
            $cmd .= "<0.$fetch_size>";
        $data = $this->smimap_run_command($cmd, true, $response, $message, $uid_support);
        do {
            $topline = trim(array_shift($data));
        } while ($topline && $topline[0] == '*' && ! preg_match('/\* [0-9]+ FETCH.*/i', $topline));
        
        $wholemessage = implode('', $data);
        if (preg_match('/\{([^\}]*)\}/', $topline, $regs)) {
            $ret = substr($wholemessage, 0, $regs[1]);
            /*
             * There is some information in the content info header that could be important
             * in order to parse html messages. Let's get them here.
             */
        } else 
            if (preg_match('/"([^"]*)"/', $topline, $regs)) {
                $ret = $regs[1];
            } else 
                if ((stristr($topline, 'nil') !== false) && (empty($wholemessage))) {
                    $ret = $wholemessage;
                } else {
                    $data = $this->smimap_run_command("FETCH $passed_id BODY[]", true, $response, $message, $uid_support);
                    array_shift($data);
                    $wholemessage = implode('', $data);
                    $ret = $wholemessage;
                }
        return $ret;
    }
}