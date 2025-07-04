#!/usr/bin/php -q
<?php

echo "There is actualy nothing here you would want to use.... except if you want to help :-)\n";
exit;

// include PEAR's System::Daemon class
require_once 'System/Daemon.php';

// appName
$msgQ = 12345;
$appName = 'eppitnicd';
$sleep = 250000; // 0.25 seconds
$sleep = 2500000; // 2.5 seconds
 
// arguments and their default values
$runmode = array(
  'no-daemon' => false,
  'help' => false,
  'write-initd' => false,
);
 
// scan command line attributes for allowed arguments
foreach ( $argv as $key => $arg ) {
  if ( substr($arg, 0, 2) == '--' && isset($runmode[substr($arg, 2)]) ) {
    $runmode[substr($arg, 2)] = true;
  }
}
 
// show all arguments
if ( $runmode['help'] == true ) {
  echo 'Usage: '.$argv[0].' [runmode]' . "\n";
  echo 'Available runmodes:' . "\n";
  foreach ( $runmode as $runmod => $value ) {
    echo ' --' . $runmod . "\n";
  }
  exit(0);
}
 
// setup application
System_Daemon::setOptions(array(
  'appName'             => $appName,
  'appDir'              => dirname(__FILE__),
  'appDescription'      => 'EPPITNIC session control daemon.',
  'authorName'          => 'Guenther Mair',
  'authorEmail'         => 'info@inet-services.it',
  'sysMaxExecutionTime' => '0',
  'sysMaxInputTime'     => '0',
  'sysMemoryLimit'      => '128M',
  //'appRunAsGID'         => 1000,
  //'appRunAsUID'         => 1000,
));

// verify that we are able to write to logfile
if ( ! is_writable(System_Daemon::opt('logLocation')) ) {
  echo "Logfile '".System_Daemon::opt('logLocation')."' is not writeable - aborting program execution.\n";
  exit(1);
}
 
// if requested, automatically create a init.d script
if ( $runmode['write-initd'] ) {
  if ( ($initd_location = System_Daemon::writeAutoRun()) === FALSE ) {
    System_Daemon::notice('unable to write init.d script');
  } else {
    System_Daemon::info('sucessfully written startup script: %s', $initd_location);
  }
}

// override default signal handler for these signals:
System_Daemon::setSigHandler(SIGTERM, 'cleanShutdown');
System_Daemon::setSigHandler(SIGQUIT, 'cleanShutdown');

// clean shutdown handler
function cleanShutdown($signal) {
  System_Daemon::warning('{appName} shuting down after receiving signal %s.', $signal);
  System_Daemon::stop();
  exit(0);
}

// shutdown after an internal program failure
function failureShutdown($reasonCode, $reason) {
  System_Daemon::warning('{appName} shuting down unexpectedly: %s.', $reason);
  System_Daemon::stop();
  exit($reasonCode);
}

// fork background process
if ( ! $runmode['no-daemon'] ) {
  System_Daemon::start();
}

// get a message queue
$key_t = msg_get_queue($msgQ, 0644);

// application starts here
System_Daemon::info('{appName} started in '.(System_Daemon::isInBackground() ? '' : 'non-' ).'daemon mode');

// main program loop
while ( ! System_Daemon::isDying() ) {

  // look at IPC message queue
  $stat = msg_stat_queue($key_t);
  System_Daemon::info('%s messages in queue.', $stat['msg_qnum']);

  // expect messages of type 1
  for ($i = 0; $i < $stat['msg_qnum']; $i++) {
    if ( msg_receive($key_t, 1, $ignore, 8192, $msg, TRUE, MSG_IPC_NOWAIT) ) {
      System_Daemon::info('Message n. %s: %s (pid %s)', $i, $msg['content'], $msg['pid']);
      if ( ! msg_send($key_t, $msg['pid'], $msg['content'] . " - received") )
        System_Daemon::err('failed to answer client request "%s"', $msg['content']);
    }
  }

  // sleep a bit
  usleep($sleep);

  // clears statcache
  System_Daemon::iterate(0);
}
 
// shutdown
failureShutdown(2, 'program run ended outside endless background loop');
