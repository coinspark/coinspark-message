<?php

/*
 * receive-coinspark-message v1.0
 * 
 * CLI wrapper for coinspark-message.php which receives CoinSpark messages
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


	if ($argc<2) {
		echo <<<HEREDOC
Usage:
php receive-coinspark-message.php <txid> <testnet (optional)>

Examples:
php receive-coinspark-message.php bdb7b22c5a529a4feff994f8886123c793113a0c89d61fa72ffa0d3276a690df
php receive-coinspark-message.php 3c62230aa2022b4213a72782b269b4a9e9fdb4965ca345cdf830a2f3aeccafad 1

HEREDOC;
		exit;
	}
	
	@list($dummy, $txid, $testnet)=$argv;
	
	require dirname(__FILE__).'/coinspark-message.php';

	$result=coinspark_message_receive($txid, $testnet);
	
	if (isset($result['error']))
		echo 'Error: '.$result['error']."\n";
	elseif (
		(count($result['message'])==1) &&
		($result['message'][0]['mimeType']=='text/plain') &&
		(!isset($result['message'][0]['fileName']))
	)
		echo $result['message'][0]['content']."\n"; // if just simple text message, output that text
	else
		print_r($result['message']);
