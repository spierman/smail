<?php
$dir = dirname(dirname(dirname(__FILE__)));
require_once $dir . '/vendor/autoload.php';
use Smail\Imap;
use Smail\Util\ComMime;

$imap = new Imap('spiderman1517650@sina.com', '15176501024btx');
// $list=$imap->mailboxSelect('Inbox');
// $list = $imap->capability();
// $list = $imap->getMailboxDelimiter();
// $list = $imap->getEmailCount('Inbox');
// $list = $imap->statusEmail('Inbox');
// $list=$imap->searchEmail('123');
$list=$imap->getMailIdsByMailbox('我的测试邮件');
// $list=$imap->mailboxList();
var_export($list);
