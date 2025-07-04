#!/usr/bin/php -q
<?php

$retries = 10;
$sleep = 500000; // 0.5 seconds
$msgQ = 12345;

// get a message queue
$key_t = msg_get_queue($msgQ, 0644);

// get PID
$pid = getmypid();

// handle message
function handleMessage($q, $msg, $msgID = FALSE) {
  global $retries, $sleep, $pid;

  $message['pid'] = $pid;
  $message['msgID'] = $msgID;
  $message['content'] = $msg;

  if ( ! msg_send($q, 1, $msg) )
    throw new Exception('Unable to send message "'.$msg.'".');

  $status = FALSE;
  for ( $i = 0; $i < $retries; $i++ ) {
    if ( msg_receive($q, $pid, $ignore, 8192, $answer, TRUE, MSG_IPC_NOWAIT) ) {
      $status = TRUE;
      break;
    }
    usleep($sleep);
  }

  if ( ! $status )
    throw new Exception('Unable to retrieve answer to message "'.$msg.'".');
  
  return $answer;
}

for ( $i = 1; $i < 10; $i++ ) {
  try {
    $answer = handleMessage($key_t, "message n. " . $i);
    echo "Answer received: '".$answer."'.\n";
  } catch (Exception $e) {
    echo "Caught exception '".$e->getMessage()."'.\n";
  }
}

