<?php

/*
 * send-coinspark-message v1.0
 * 
 * CLI wrapper for coinspark-message.php which sends CoinSpark messages
 *
 * Copyright (c) Coin Sciences Ltd - http://coinspark.org/
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */


	if ($argc<4) {
		echo <<<HEREDOC
Usage:
php send-coinspark-message.php <send-address> <send-amount> <message-text> <testnet (optional)>

Examples:
php send-coinspark-message.php 149wHUMa41Xm2jnZtqgRx94uGbZD9kPXnS 0.001 'Here is some bitcoin for you.'
php send-coinspark-message.php mzEJxCrdva57shpv62udriBBgMECmaPce4 0.001 'Here is some testnet bitcoin for you!' 1

HEREDOC;
		exit;
	}
	
	@list($dummy, $send_address, $send_amount, $message_text, $testnet)=$argv;
	
	require dirname(__FILE__).'/coinspark-message.php';

	$result=coinspark_message_send($send_address, $send_amount, $message_text, $testnet);
	
	if (isset($result['error']))
		echo 'Error: '.$result['error']."\n";
	else
		echo $result['txid']."\n";
