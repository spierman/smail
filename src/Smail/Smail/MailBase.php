<?php
namespace Smail;

use Smail\Util\ComAuth;
use Smail\Util\ComDate;
use Smail\Util\ComFunc;
use Smail\Util\ComDection;
use Smail\Mime\ComMime;

class MailBase
{

    var $username = '';

    var $password = '';

    var $imap_server = '';

    var $use_tls = '';

    var $imap_auth_mech = '';

    var $imap_port = '';

    var $smtp_server = '';

    var $smtp_port = '';

    var $mail_domain = '';

    var $smtp_auth_mech = '';

    var $imap_server_type = '';

    var $error = '';

    var $imapStream;

    public function __construct()
    {}

    /**
     * 登录邮件服务器
     *
     * @param string $username
     *            用户名
     * @param string $password
     *            密码
     */
    public function login()
    {
        if ($this->use_tls == true && extension_loaded('openssl')) {
            $this->imap_server = 'tls://' . $this->imap_server;
        }
        $this->imapStream = @fsockopen($this->imap_server, $this->imap_port, $error_number, $error_string, 15);
        $server_info = fgets($this->imapStream, 1024);
        if (($this->imap_auth_mech == 'cram-md5') or ($this->imap_auth_mech == 'digest-md5')) {
            $tag = $this->smimap_session_id(false);
            if ($this->imap_auth_mech == 'digest-md5') {
                $query = $tag . " AUTHENTICATE DIGEST-MD5\r\n";
            } elseif ($this->imap_auth_mech == 'cram-md5') {
                $query = $tag . " AUTHENTICATE CRAM-MD5\r\n";
            }
            fputs($this->imapStream, $query);
            $answer = $this->smimap_fgets();
            // Trim the "+ " off the front
            $response = explode(" ", $answer, 3);
            if ($response[0] == '+') {
                // Got a challenge back
                $challenge = $response[1];
                if ($this->imap_auth_mech == 'digest-md5') {
                    $reply = ComAuth::digest_md5_response($this->username, $this->password, $challenge, 'imap', $imap_server);
                } elseif ($this->imap_auth_mech == 'cram-md5') {
                    $reply = ComAuth::cram_md5_response($this->username, $this->password, $challenge);
                }
                fputs($this->imapStream, $reply);
                $read = $this->smimap_fgets();
                if ($this->imap_auth_mech == 'digest-md5') {
                    if (substr($read, 0, 1) == '+') {
                        fputs($this->imapStream, "\r\n");
                        $read = $this->smimap_fgets();
                    }
                }
                $results = explode(" ", $read, 3);
                $response = $results[1];
                $message = $results[2];
            } else {
                $response = "BAD";
                $message = 'IMAP server does not appear to support the authentication method selected.';
                $message .= '  Please contact your system administrator.';
            }
        } elseif ($this->imap_auth_mech == 'login') {
            if (stristr($server_info, 'LOGINDISABLED')) {
                $response = 'BAD';
                $message = "The IMAP server is reporting that plain text logins are disabled." . "Using CRAM-MD5 or DIGEST-MD5 authentication instead may work.";
                if (! $this->use_tls) {
                    $message .= "Also, the use of TLS may allow SquirrelMail to login.";
                }
                $message .= "Please contact your system administrator and report this error.";
            } else {
                if (ComMime::is8bit($this->username) || ComMime::is8bit($this->password)) {
                    $query['command'] = 'LOGIN';
                    $query['literal_args'][0] = $this->username;
                    $query['literal_args'][1] = $this->password;
                    $read = $this->smimap_run_literal_command($query, false, $response, $message);
                } else {
                    $query = 'LOGIN "' . ComFunc::quoteimap($this->username) . '"' . ' "' . ComFunc::quoteimap($this->password) . '"';
                    $read = $this->smimap_run_command($query, false, $response, $message);
                }
            }
        } elseif ($this->imap_auth_mech == 'plain') {
            $response = "BAD";
            $message = 'smail does not support SASL PLAIN yet. Rerun conf.pl and use login instead.';
        } else {
            $response = "BAD";
            $message = "Internal smail error - unknown IMAP authentication method chosen.  Please contact the developers.";
        }
        if ($response != 'OK') {
            if (! $hide) {
                if ($response != 'NO') {
                    $message = htmlspecialchars($message);
                    if ($response == 'BAD') {
                        $string = sprintf("Bad request: %s", $message);
                    } else {
                        $string = sprintf("Unknown error: %s", $message);
                    }
                    if (isset($read) && is_array($read)) {
                        $string .= 'Read data:';
                        foreach ($read as $line) {
                            $string .= htmlspecialchars($line);
                        }
                    }
                } else {
                    $this->logout();
                }
            }
            throw new \Exception($message);
        }
    }

    /**
     * 注销登录
     */
    public function logout()
    {
        $this->smimap_run_command('LOGOUT', false, $response, $message);
    }

    /**
     * 运行命令行
     *
     * @param string $query            
     * @param string $handle_errors            
     * @param string $response            
     * @param string $message            
     * @param string $unique_id            
     * @return mixed $read
     */
    protected function smimap_run_command_list($query, $handle_errors, &$response, &$message, $unique_id = false)
    {
        if ($this->imapStream) {
            $sid = $this->smimap_session_id($unique_id);
            fputs($this->imapStream, $sid . ' ' . $query . "\r\n");
            $read = $this->smimap_read_data_list($sid, $handle_errors, $response, $message, $query);
            return $read;
        } else {
            return false;
        }
    }

    /**
     * get the section of the mail's id
     *
     * @param array $messages_array            
     * @return string 1:2:3
     */
    protected function smimap_message_list_squisher($messages_array)
    {
        if (! is_array($messages_array)) {
            return $messages_array;
        }
        sort($messages_array, SORT_NUMERIC);
        $msgs_str = '';
        while ($messages_array) {
            $start = array_shift($messages_array);
            $end = $start;
            while (isset($messages_array[0]) && $messages_array[0] == $end + 1) {
                $end = array_shift($messages_array);
            }
            if ($msgs_str != '') {
                $msgs_str .= ',';
            }
            $msgs_str .= $start;
            if ($start != $end) {
                $msgs_str .= ':' . $end;
            }
        }
        return $msgs_str;
    }

    /**
     * 获取ID组 1:1000,并放入session
     *
     * @param array $mbxresponse            
     */
    private function smimap_get_php_sort_order($mbxresponse)
    {
        $uid_support = true;
        $php_sort_array = array();
        if ($uid_support) {
            if (isset($mbxresponse['UIDNEXT'])) {
                $uidnext = $mbxresponse['UIDNEXT'] - 1;
            } else {
                $uidnext = '*';
            }
            $query = "SEARCH UID 1:$uidnext";
            $uids = $this->smimap_run_command($query, true, $response, $message, true);
            if (isset($uids[0])) {
                $php_sort_array = array();
                // EIMS workaround. EIMS returns the result as multiple untagged SEARCH responses
                foreach ($uids as $line) {
                    if (preg_match("/^\* SEARCH (.+)$/", $line, $regs)) {
                        $php_sort_array += preg_split("/ /", trim($regs[1]));
                    }
                }
            }
            if (! preg_match("/OK/", $response)) {
                $php_sort_array = 'no';
            }
        } else {
            $qty = $mbxresponse['EXISTS'];
            $php_sort_array = range(1, $qty);
        }
        return $php_sort_array;
    }

    /**
     * 创建会话ID
     *
     * @param string $unique_id            
     */
    public function smimap_session_id($unique_id = FALSE)
    {
        static $smimap_session_id = 1;
        if (! $unique_id) {
            return (sprintf("A%03d", $smimap_session_id ++));
        } else {
            return (sprintf("A%03d", $smimap_session_id ++) . ' UID');
        }
    }

    /**
     * 获取会话信息
     */
    private function smimap_fgets()
    {
        $read = '';
        $buffer = 4096;
        $results = '';
        $offset = 0;
        while (strpos($results, "\r\n", $offset) === false) {
            if (! ($read = fgets($this->imapStream, $buffer))) {
                /* this happens in case of an error */
                /* reset $results because it's useless */
                $results = false;
                throw new \Exception($read);
            }
            if ($results != '') {
                $offset = strlen($results) - 1;
            }
            $results .= $read;
        }
        return $results;
    }

    /**
     * 运行命令
     *
     * @param
     *            $query
     * @param
     *            $handle_errors
     * @param
     *            $response
     * @param
     *            $message
     * @param
     *            $unique_id
     */
    private function smimap_run_literal_command($query, $handle_errors, &$response, &$message, $unique_id = false)
    {
        if ($this->imapStream) {
            $sid = $this->smimap_session_id($unique_id);
            $command = sprintf("%s {%d}\r\n", $query['command'], strlen($query['literal_args'][0]));
            fputs($this->imapStream, $sid . ' ' . $command);
            
            // TODO: Put in error handling here //
            $read = $this->smimap_read_data($sid, $handle_errors, $response, $message, $query['command']);
            
            $i = 0;
            $cnt = count($query['literal_args']);
            while ($i < $cnt) {
                if (($cnt > 1) && ($i < ($cnt - 1))) {
                    $command = sprintf("%s {%d}\r\n", $query['literal_args'][$i], strlen($query['literal_args'][$i + 1]));
                } else {
                    $command = sprintf("%s\r\n", $query['literal_args'][$i]);
                }
                fputs($this->imapStream, $command);
                $read = $this->smimap_read_data($sid, $handle_errors, $response, $message, $query['command']);
                $i ++;
            }
            return $read;
        } else {
            throw new \Exception('unknown imap stream');
        }
    }

    /**
     * 读取会话返回数据
     *
     * @param
     *            $tag_uid
     * @param
     *            $handle_errors
     * @param
     *            $response
     * @param
     *            $message
     * @param
     *            $query
     * @param
     *            $filter
     * @param
     *            $outputstream
     * @param
     *            $no_return
     */
    protected function smimap_read_data($tag_uid, $handle_errors, &$response, &$message, $query = '')
    {
        $res = $this->smimap_read_data_list($tag_uid, $handle_errors, $response, $message, $query);
        return $res[0];
    }

    /**
     * 运行命令
     *
     * @param
     *            $query
     * @param
     *            $handle_errors
     * @param
     *            $response
     * @param
     *            $message
     * @param
     *            $unique_id
     * @param
     *            $filter
     * @param
     *            $outputstream
     * @param
     *            $no_return
     */
    public function smimap_run_command($query, $handle_errors, &$response, &$message, $unique_id = false)
    {
        if ($this->imapStream) {
            $sid = $this->smimap_session_id($unique_id);
            fputs($this->imapStream, $sid . ' ' . $query . "\r\n");
            $read = $this->smimap_read_data($sid, $handle_errors, $response, $message, $query);
            return $read;
        } else {
            throw new \Exception('imap steam is null');
        }
    }

    /**
     * 读取数据列表
     *
     * @param
     *            $tag_uid
     * @param
     *            $handle_errors
     * @param
     *            $response
     * @param
     *            $message
     * @param
     *            $query
     * @param
     *            $filter
     * @param
     *            $outputstream
     * @param
     *            $no_return
     */
    private function smimap_read_data_list($tag_uid, $handle_errors, &$response, &$message, $query = '')
    {
        $read = '';
        $tag_uid_a = explode(' ', trim($tag_uid));
        $tag = $tag_uid_a[0];
        $resultlist = array();
        $data = array();
        $read = $this->smimap_fgets();
        $i = 0;
        while ($read) {
            $char = $read{0};
            switch ($char) {
                case '+':
                    {
                        $response = 'OK';
                        break 2;
                    }
                default:
                    $read = $this->smimap_fgets();
                    break;
                case $tag{0}:
                    {
                        $arg = '';
                        $i = strlen($tag) + 1;
                        $s = substr($read, $i);
                        if (($j = strpos($s, ' ')) || ($j = strpos($s, "\n"))) {
                            $arg = substr($s, 0, $j);
                        }
                        $found_tag = substr($read, 0, $i - 1);
                        if ($arg && $found_tag == $tag) {
                            switch ($arg) {
                                case 'OK':
                                case 'BAD':
                                case 'NO':
                                case 'BYE':
                                case 'PREAUTH':
                                    $response = $arg;
                                    $message = trim(substr($read, $i + strlen($arg)));
                                    break 3; /* switch switch while */
                                default:
									/* this shouldn't happen */
									$response = $arg;
                                    $message = trim(substr($read, $i + strlen($arg)));
                                    break 3; /* switch switch while */
                            }
                        } elseif ($found_tag !== $tag) {
                            /* reset data array because we do not need this reponse */
                            $data = array();
                            $read = $this->smimap_fgets();
                            break;
                        }
                    } // end case $tag{0}
                
                case '*':
                    {
                        if (preg_match('/^\*\s\d+\sFETCH/', $read)) {
                            /* check for literal */
                            $s = substr($read, - 3);
                            $fetch_data = array();
                            do { /*
                                  * outer loop, continue until next untagged fetch
                                  * or tagged reponse
                                  */
                                do { /*
                                      * innerloop for fetching literals. with this loop
                                      * we prohibid that literal responses appear in the
                                      * outer loop so we can trust the untagged and
                                      * tagged info provided by $read
                                      */
                                    $read_literal = false;
                                    if ($s === "}\r\n") {
                                        $j = strrpos($read, '{');
                                        $iLit = substr($read, $j + 1, - 3);
                                        $fetch_data[] = $read;
                                        $sLiteral = $this->smimap_fread($iLit);
                                        if ($sLiteral === false) { /* error */
                                            break 4; /* while while switch while */
                                        }
                                        /* backwards compattibility */
                                        $aLiteral = explode("\n", $sLiteral);
                                        /* release not neaded data */
                                        unset($sLiteral);
                                        foreach ($aLiteral as $line) {
                                            $fetch_data[] = $line . "\n";
                                        }
                                        /* release not neaded data */
                                        unset($aLiteral);
                                        /*
                                         * next fgets belongs to this fetch because
                                         * we just got the exact literalsize and there
                                         * must follow data to complete the response
                                         */
                                        $read = $this->smimap_fgets();
                                        if ($read === false) { /* error */
                                            break 4; /* while while switch while */
                                        }
                                        $s = substr($read, - 3);
                                        $read_literal = true;
                                        continue;
                                    } else {
                                        $fetch_data[] = $read;
                                    }
                                    /*
                                     * retrieve next line and check in the while
                                     * statements if it belongs to this fetch response
                                     */
                                    $read = $this->smimap_fgets();
                                    if ($read === false) { /* error */
                                        break 4; /* while while switch while */
                                    }
                                    /* check for next untagged reponse and break */
                                    if ($read{0} == '*')
                                        break 2;
                                    $s = substr($read, - 3);
                                } while ($s === "}\r\n" || $read_literal);
                                $s = substr($read, - 3);
                            } while ($read{0} !== '*' && substr($read, 0, strlen($tag)) !== $tag);
                            $resultlist[] = $fetch_data;
                            /* release not neaded data */
                            unset($fetch_data);
                        } else {
                            $s = substr($read, - 3);
                            do {
                                if ($s === "}\r\n") {
                                    $j = strrpos($read, '{');
                                    $iLit = substr($read, $j + 1, - 3);
                                    // check for numeric value to avoid that untagged responses like:
                                    // * OK [PARSE] Unexpected characters at end of address: {SET:debug=51}
                                    // will trigger literal fetching ({SET:debug=51} !== int )
                                    if (is_numeric($iLit)) {
                                        $data[] = $read;
                                        $sLiteral = fread($this->imapStream, $iLit);
                                        if ($sLiteral === false) { /* error */
                                            $read = false;
                                            break 3; /* while switch while */
                                        }
                                        $data[] = $sLiteral;
                                        $data[] = $this->smimap_fgets();
                                    } else {
                                        $data[] = $read;
                                    }
                                } else {
                                    $data[] = $read;
                                }
                                $read = $this->smimap_fgets();
                                if ($read === false) {
                                    break 3; /* while switch while */
                                } else 
                                    if ($read{0} == '*') {
                                        break;
                                    }
                                $s = substr($read, - 3);
                            } while ($s === "}\r\n");
                            break 1;
                        }
                        break;
                    } // end case '*'
            } // end switch
        } // end while
        
        /* error processing in case $read is false */
        if ($read === false) {
            unset($data);
            $error = "ERROR: Connection dropped by IMAP server.";
            $cmd = explode(' ', $query);
            $cmd = strtolower($cmd[0]);
            if ($query != '' && $cmd != 'login') {
                $error .= "Query:" . ' ' . $query;
            }
            echo $string;
        }
        
        /* Set $resultlist array */
        if (! empty($data)) {
            $resultlist[] = $data;
        } elseif (empty($resultlist)) {
            $resultlist[] = array();
        }
        /* Return result or handle errors */
        if ($handle_errors == false) {
            return $resultlist;
        }
        $close_connection = false;
        switch ($response) {
            case 'OK':
                return $resultlist;
                break;
            case 'NO':
				/* ignore this error from M$ exchange, it is not fatal (aka bug) */
				if (strstr($message, 'command resulted in') === false) {
                    $error = 'command line:' . $query . 'response:' . $message;
                    $close_connection = true;
                }
                break;
            case 'BAD':
                $error = 'ERROR: Bad or malformed request' . "Query:" . $query . "Server responded:" . $message;
                $close_connection = true;
                break;
            case 'BYE':
                $error = "ERROR: IMAP server closed the connection." . "Query:" . $query . "Server responded:" . $message;
                $close_connection = true;
                break;
            default:
                $error = 'ERROR: Unknown IMAP response' . 'Query:' . $query . 'Server responded:' . $message;
                /*
                 * the error is displayed but because we don't know the reponse we
                 * return the result anyway
                 */
                $close_connection = true;
                break;
        }
        if ($close_connection) {
            $this->logout();
        }
        if ($error) {
            throw new \Exception($error);
        }
    }

    /**
     * 从会话流中读取信息
     *
     * @param unknown_type $iSize            
     * @param unknown_type $filter            
     * @param unknown_type $outputstream            
     * @param unknown_type $no_return            
     */
    protected function smimap_fread($iSize)
    {
        $iBufferSize = $iSize;
        // see php bug 24033. They changed fread behaviour %$^&$%
        // $iBufferSize = 7800; // multiple of 78 in case of base64 decoding.
        if ($iSize < $iBufferSize) {
            $iBufferSize = $iSize;
        }
        
        $iRetrieved = 0;
        $results = '';
        $sRead = $sReadRem = '';
        // NB: fread can also stop at end of a packet on sockets.
        while ($iRetrieved < $iSize) {
            $sRead = fread($this->imapStream, $iBufferSize);
            $iLength = strlen($sRead);
            $iRetrieved += $iLength;
            $iRemaining = $iSize - $iRetrieved;
            if ($iRemaining < $iBufferSize) {
                $iBufferSize = $iRemaining;
            }
            if ($sRead == '') {
                $results = false;
                break;
            }
            if ($sReadRem != '') {
                $sRead = $sReadRem . $sRead;
                $sReadRem = '';
            }
            
            if ($filter && $sRead != '') {
                $sReadRem = $filter($sRead);
            }
            $results .= $sRead;
        }
        return $results;
    }

    /**
     * Retreive the CAPABILITY string from the IMAP server.
     * If capability is set, returns only that specific capability,
     * else returns array of all capabilities.
     *
     * @param string $capability            
     * @return array $smimap_capabilities
     *        
     */
    public function capability($capability='')
    {
        $read = $this->smimap_run_command('CAPABILITY', true, $a, $b);
        $c = explode(' ', $read[0]);
        for ($i = 2; $i < count($c); $i ++) {
            $cap_list = explode('=', $c[$i]);
            if (isset($cap_list[1])) {
                // FIX ME. capabilities can occure multiple times.
                // THREAD=REFERENCES THREAD=ORDEREDSUBJECT
                $smimap_capabilities[$cap_list[0]] = $cap_list[1];
            } else {
                $smimap_capabilities[$cap_list[0]] = TRUE;
            }
        }
        if ($capability) {
            if (isset($smimap_capabilities[$capability])) {
                return $smimap_capabilities[$capability];
            } else {
                return false;
            }
        }
        return $smimap_capabilities;
    }
}