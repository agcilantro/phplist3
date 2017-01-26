<?php

/*

This file is a patched version of getrss.php from phpList 2.11.3. It removes reliance on ONYX for RSS processing and provides support for the more modern RSS parsing services of Simplepie instead.



For more information about Simplepie, please see:

http://simplepie.org/



For documentation and instructions regarding this patch, please see:

http://whereelsetoputit.com/blog/upgrade-phplist-to-simplepie/



Modified and released by Dr Greg Mulhauser, 30 July 2007.



Comments on the patch are included inline, preceded by "// GM - ".



Like phpList, this patch is released under GPL v2.



If you distribute this patch further, or incorporate it into the main phplist release, I would be grateful for a credit and a note of my website.



Unlike tincan...cough...ahem...I have NOT inserted any headers, footers, hidden text links, or other linkspam back to my commercial sites. Just a nod would be nice, though. :-)

*/



require_once 'accesscheck.php';

if (!$GLOBALS['commandline']) {

  ob_end_flush();

  if (!MANUALLY_PROCESS_RSS) {

    print $GLOBALS['I18N']->get('This page can only be called from the commandline');

    return;

  }

} else {

  ob_end_clean();

  print ClineSignature();

  print $GLOBALS['I18N']->get('Getting and Parsing the RSS sources') . "\n";

  ob_start();

}



# @@@@ Not sure if this is 118nable.

function ProcessError ($message) {

  print "$message";

  logEvent("Error: $message");

  finish("error",$message);

  exit;

}



function output($line) {

  if ($GLOBALS['commandline']) {

    ob_end_clean();

    print strip_tags($line)."\n";

    ob_start();

  } else {

    print "$line<br/>\n";

  }

  flush();

}



register_shutdown_function('finish');



function finish ($flag = "info",$message = 'finished') {

  global $nothingtodo,$failreport,$mailreport,$process_id;



  if ($flag == 'error') {

    $subject = $GLOBALS['I18N']->get('Rss Errors');

  } else {

    $subject = $GLOBALS['I18N']->get('Rss Results');

  }



  releaseLock($process_id);



  if (!TEST && !$nothingtodo) {

    if ($mailreport)

      sendReport($subject,$mailreport);

    if ($failreport)

      sendReport($GLOBALS['I18N']->get('Rss Failure report'),$failreport);

  }

}



# we don not want to timeout or abort

$abort = ignore_user_abort(1);

set_time_limit(600);



// GM - remove ONYXRSS and replace with Simplepie

// GM - alter the path to match your installation of Simplepie

// GM - if it's in your include path, what is shown here should work fine

//include 'onyxrss/onyx-rss.php';

include 'simplepie/simplepie.inc';

error_reporting(0);

$nothingtodo = 1;

$mailreport = '';

$process_id = getPageLock();



$req = Sql_Query("select rssfeed,id from {$tables['list']} where rssfeed != \"\" order by listorder");

while ($feed = Sql_Fetch_Row($req)) {

  $nothingtodo = 0;

  output( '<hr>' . $GLOBALS['I18N']->get('Parsing') . ' ' . $feed[0] . '..');

  flush();

  $report = $GLOBALS['I18N']->get('Parsing') . ' ' . $feed[0];

  $mailreport .= "\n$feed[0] ";

  $itemcount = 0;

  $newitemcount = 0;

// GM - replace setup with corresponding setup for Simplepie

// GM - we won't bother with caching, since cache life for ONYX was only set as 3 minutes anyway

//  $rss =& new ONYX_RSS();

//  $rss->setDebugMode(false);

//  $rss->setCachePath($tmpdir);

$rss =& new SimplePie();

$rss->enable_cache(false);



  keepLock($process_id);



// GM - set feed URL and init the parsing, but without the cache file

//  $parseresult = $rss->parse($feed[0],"rss-cache".$GLOBALS["database_name"].$feed[1]);

$rss->set_feed_url($feed[0]);

$parseresult = $rss->init();



  if ($parseresult) {

    $report .= ' ' . $GLOBALS['I18N']->get('ok') . "\n";

   $mailreport .= " 'ok ";

    output( '..' . $GLOBALS['I18N']->get('ok') . '<br />');

  } else {

   $report .= ' ' . $GLOBALS['I18N']->get('failed') . "\n";

   output( '..' . $GLOBALS['I18N']->get('failed') . '<br />');

    $mailreport .= ' ' . $GLOBALS['I18N']->get('failed') . "\n";

// GM - use slightly different error reporting from Simplepie

//    $mailreport .= $rss->lasterror;

    $mailreport .= $rss->error;

// GM - same again...

//    $failreport .= "\n" . $feed[0] . ' ' . $GLOBALS['I18N']->get('failed') . "\n" . $rss->lasterror;

    $failreport .= "\n" . $feed[0] . ' ' . $GLOBALS['I18N']->get('failed') . "\n" . $rss->error;

  }

  flush();

  if ($parseresult) {

// GM - changing from while to foreach, and using appropriate Simplepie construction

//    while ($item = $rss->getNextItem()) {

      foreach ($rss->get_items() as $item) {

      set_time_limit(60);

      $alive = checkLock($process_id);

      if ($alive)

        keepLock($process_id);

      else

        ProcessError($GLOBALS['I18N']->get('Process Killed by other process'));

      $itemcount++;

// GM - alter the following SQL to ensure we're grabbing the data using Simplepie-speak

// GM - also alter according to fix for phpList bug 0003739: same RSS feed cannot be used for two lists: added source to make combination of title, source and url unique

// GM - if you don't like the fix for bug 0003739, just back it out (the bug fix raises another issue: phpList will incorrectly send two copies of the same item to the same subscriber, if that person is subscribed to two separate lists which each include content from the same feed)

      Sql_Query(sprintf('select * from %s where title = "%s" and link = "%s" and source = "%s"',

        $tables["rssitem"],addslashes(substr($item->get_title(),0,100)),addslashes(substr($item->get_permalink(),0,254)), addslashes($feed[0])));

      if (!Sql_Affected_Rows()) {

        $newitemcount++;

// GM - same again on the SQL

        Sql_Query(sprintf('insert into %s (title,link,source,list,added)

          values("%s","%s","%s",%d,now())',

          $tables["rssitem"],addslashes($item->get_title()),addslashes($item->get_permalink()),addslashes($feed[0]),$feed[1]));

        $itemid = Sql_Insert_Id();

        

// GM - the following array sets out which of the feed tags we would like to record

// GM - the array keys (the names) will remain consistent in the database, even if the syntax of tags in the feeds changes over time

// GM - the initial tests on author and category are just because Simplepie returns these as objects; note that this method extracts just one author, just one category, for each item

// GM - of course we can add new tags to use whenever we want, or delete some of these

// GM - the beauty of using Simplepie here to extract the tags, each with a consistent and uniform name, is that you can then reference the contents of these tags by name in your RSS parsing template given on the main phpList configuration page



if ($thisauthor = $item->get_author()) {

$author = $thisauthor->get_name();

}

else {$author='';}

if ($thiscategory = $item->get_category()) {

$category = $thiscategory->get_label();

}

else {$category='';}



$tagstouse = array (

					"author" => $author,

					"description" => $item->get_description(),

					"pubdate" => $item->get_local_date('%a, %d %b %Y'),

					"category" => $category,

					"guid" => $item->get_id()

                      );

                      

// GM - new foreach construction to make use of our array       

//        foreach ($item as $key => $val) {

        foreach ($tagstouse as $key => $val) {

        

// GM - we don't need this test any more, because the array won't have title or link in it        

//          if ($item != 'title' && $item != 'link') {

            Sql_Query(sprintf('insert into %s (itemid,tag,data)

              values("%s","%s","%s")',

              $tables["rssitem_data"],$itemid,$key,addslashes($val)));

// GM - the below was the closing bracket for the if

//          }

        }

      }

    }

    output(sprintf('<br/>%d %s, %d %s',$itemcount,$GLOBALS['I18N']->get('items'),$newitemcount,$GLOBALS['I18N']->get('new items')));

    $report .= sprintf('%d items, %d new items'."\n",$itemcount,$newitemcount);

    $mailreport .= sprintf('-> %d items, %d new items'."\n",$itemcount,$newitemcount);

  }

  flush();

  Sql_Query(sprintf('insert into %s (listid,type,entered,info) values(%d,"retrieval",now(),"%s")',

    $tables["listrss"],$feed[1],$report));

  logEvent($report);

}

if ($nothingtodo) {

  print $GLOBALS['I18N']->get('Nothing to do');

}





?>

