<?php

/*
 * coinspark-message v1.0
 * 
 * A PHP script/library to generate bitcoin transactions with CoinSpark messages
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


	require_once dirname(__FILE__).'/coinspark.php';
	
	
	define('CONST_BITCOIN_CMD', '/usr/bin/bitcoin-cli'); // path to bitcoin executable on this server
	define('CONST_BITCOIN_FEE', 0.00010000); // transaction fee to pay
	define('CONST_DELIVERY_SERVERS', 'https://msg1.coinspark.org/,https://msg2.coinspark.org/,https://msg3.coinspark.org/');
		// comma-delimited list of CoinSpark message delivery servers to try
	define('CONST_MESSAGE_KEEP_SECS', 604800); // how long message should be kept on delivery server
	define('CONST_MAX_OP_RETURN_LEN', 40); // limit on bitcoin OP_RETURN size (40 as of bitcoin 0.9, soon to be 80)
	define('CONST_COINSPARK_DEBUG', false);
	

//	Sending CoinSpark messages

	function coinspark_message_send($send_address, $send_amount, $message_text, $testnet=false)
	{
	
	//	If $send_address is a CoinSpark address, check it has the appropriate flag
		
		$address=new CoinSparkAddress();
		if ($address->decode($send_address)) {
			if (!($address->addressFlags & COINSPARK_ADDRESS_FLAG_TEXT_MESSAGES))
				return array('error' => 'The CoinSpark address does not appear to support text messages.');

			$bitcoin_address=$address->bitcoinAddress;

		} else
			$bitcoin_address=$send_address;

			
	//	Verify bitcoin and the address
			
		if (!file_exists(CONST_BITCOIN_CMD))
			return array('error' => 'Please check CONST_BITCOIN_CMD is set correctly');
			
		$result=coinspark_bitcoin_cli('validateaddress', $testnet, $bitcoin_address);
		if (!@$result['isvalid'])
			return array('error' => 'Bitcoin address could not be validated: '.$bitcoin_address);
			
		if (!strlen($message_text))
			return array('error' => 'No message is attached.');


	//	Verify all listed message servers are valid
	
		$server_urls=explode(',', CONST_DELIVERY_SERVERS);

		foreach ($server_urls as $server_url) {
			$parsed=coinspark_parse_server_url($server_url, COINSPARK_MESSAGE_SERVER_HOST_MAX_LEN, COINSPARK_MESSAGE_SERVER_PATH_MAX_LEN);
			if (isset($parsed['error']))
				return array('error' => 'Server in CONST_DELIVERY_SERVERS not valid: '.$server_url.' - '.$parsed['error']);
		}
	
	
	//	List and sort unspent inputs by priority
	
		$unspent_inputs=coinspark_bitcoin_cli('listunspent', $testnet, 0);		
		if (!is_array($unspent_inputs))
			return array('error' => 'Could not retrieve list of unspent inputs');
		
		foreach ($unspent_inputs as $index => $unspent_input)
			$unspent_inputs[$index]['priority']=$unspent_input['amount']*$unspent_input['confirmations']; // see: https://en.bitcoin.it/wiki/Transaction_fees

		coinspark_sort_by($unspent_inputs, 'priority');
		$unspent_inputs=array_reverse($unspent_inputs); // now in descending order of priority
	

	//	Identify which inputs should be spent
	
		$inputs_spend=array();
		$input_amount=0;
		$output_amount=$send_amount+CONST_BITCOIN_FEE;
		$sender_address=null;
		
		foreach ($unspent_inputs as $unspent_input) {
			$inputs_spend[]=$unspent_input;
			
			if (!isset($sender_address)) // use first address for identifying self to delivery server
				$sender_address=$unspent_input['address'];
			
			$input_amount+=$unspent_input['amount'];
			if ($input_amount>=$output_amount)
				break; // stop when we have enough
		}
		
		if ($input_amount<$output_amount)
			return array('error' => 'Not enough funds are available to cover the amount and fee');
	
	
	//	Build the initial raw transaction
			
		$change_amount=$input_amount-$output_amount;		
		$change_address=coinspark_bitcoin_cli('getrawchangeaddress', $testnet);
		
		$raw_txn=coinspark_bitcoin_cli('createrawtransaction', $testnet, $inputs_spend, array(
			$bitcoin_address => (float)$send_amount,
			$change_address => $change_amount,
		));

	
	//	Find an appropriate messaging server
	
		shuffle($server_urls);
		$salt=sha1(str_shuffle(file_get_contents(__FILE__)), true);
		
		foreach ($server_urls as $server_url) {
			$response=coinspark_json_rpc_call($server_url, 'coinspark_message_pre_create', array(
				'testnet' => $testnet ? true : false,
				'sender' => $sender_address,
				'ispublic' => false,
				'recipients' => array($bitcoin_address),
				'keepseconds' => CONST_MESSAGE_KEEP_SECS,
				'salt' => base64_encode($salt),
				'message' => array(
					array(
						'mimetype' => 'text/plain',
						'filename' => null,
						'bytes' => strlen($message_text),
					),
				),
			));
			
			$nonce=@$response['result']['nonce'];
			if (strlen($nonce)) // we found a server to talk to
				break;
		}

		if (!strlen($nonce))
			return array('error' => 'Could not find a suitable message delivery server');


	//	Create the message OP_RETURN metadata
	
		$parsed=coinspark_parse_server_url($server_url, COINSPARK_MESSAGE_SERVER_HOST_MAX_LEN, COINSPARK_MESSAGE_SERVER_PATH_MAX_LEN);
			// no need to verify here since we already verified all possibilities earlier
		
		$message=new CoinSparkMessage();
		$message->useHttps=$parsed['useHttps'];
		$message->serverHost=$parsed['serverHost'];
		$message->usePrefix=$parsed['usePrefix'];
		$message->serverPath=$parsed['serverPath'];
		$message->isPublic=false;
		
		$ioRange=new CoinSparkIORange();
		$ioRange->first=0;
		$ioRange->count=1; // message is for first output only
		$message->outputRanges[0]=$ioRange;
		
		$countOutputs=2;
		$messageParts=array(
			array(
				'mimeType' => 'text/plain',
				'fileName' => null,
				'content' => $message_text,
			),
		);
		
		$message->hash=CoinSparkCalcMessageHash($salt, $messageParts);
		$message->hashLen=$message->calcHashLen($countOutputs, CONST_MAX_OP_RETURN_LEN);
		
		$metadata=$message->encode($countOutputs, CONST_MAX_OP_RETURN_LEN);
		if (!isset($metadata))
			return array('error' => 'Failed to encode message metadata, server URL likely too long');
		
	
	//	Unpack the raw transaction, add the OP_RETURN, and re-pack it
		
		$txn_unpacked=coinspark_unpack_raw_txn($raw_txn);
	
		$txn_unpacked['vout'][]=array(
			'value' => 0,
			'scriptPubKey' => CoinSparkMetadataToScript($metadata, true), // here's the OP_RETURN
		);
			
		$raw_txn=coinspark_pack_raw_txn($txn_unpacked);

		
	//	Sign the transaction
	
		$signed_txn=coinspark_bitcoin_cli('signrawtransaction', $testnet, $raw_txn);
		if (!$signed_txn['complete'])
			return array('error' => 'Could not sign the transaction');
			
			
	//	Get the txid
	
		$decoded_txn=coinspark_bitcoin_cli('decoderawtransaction', $testnet, $signed_txn['hex']);
		$decoded_txid=@$decoded_txn['txid'];
		if (strlen($decoded_txid)!=64)
			return array('error' => 'Could not get txid from decoded signed transaction');
	

	//	Get the public key for the address
	
		$validated=coinspark_bitcoin_cli('validateaddress', $testnet, $sender_address);
		$pubkey=@$validated['pubkey'];
		if (!strlen($pubkey))
			return array('error' => 'Could not get the public key for this address');

	
	//	Sign the request nonce
	
		$signature=coinspark_bitcoin_cli('signmessage', $testnet, $sender_address, $nonce);
		if (!is_string($signature))
			return array('error' => 'Failed to sign the nonce for delivery server');
	
	
	//	Create the message at the delivery server
	
		$response=coinspark_json_rpc_call($server_url, 'coinspark_message_create', array(
			'testnet' => $testnet ? true : false,
			'sender' => $sender_address,
			'nonce' => $nonce,
			'pubkey' => $pubkey,
			'signature' => $signature,
			'txid' => $decoded_txid,
			'ispublic' => false,
			'recipients' => array($bitcoin_address),
			'keepseconds' => CONST_MESSAGE_KEEP_SECS,
			'salt' => base64_encode($salt),
			'message' => array(
				array(
					'mimetype' => 'text/plain',
					'filename' => null,
					'content' => base64_encode($message_text),
				),
			),
		));
		
		$server_txid=@$response['result']['txid'];
		if (!strlen($server_txid))
			return array('error' => 'Failed to create message at '.$server_url.': '.@$response['error']['message']);


	//	Send the transaction

		$sent_txid=coinspark_bitcoin_cli('sendrawtransaction', $testnet, $signed_txn['hex']);
		if (strlen($sent_txid)!=64)
			return array('error' => 'Could not send the transaction');
			
		if ($decoded_txid!=$sent_txid)
			return array('error' => 'Sent txid ('.$sent_txid.') did not match txid sent to delivery server ('.$decoded_txid.')');
	
	
	//	Return the result if successful
			
		return array('txid' => $sent_txid);
	}
	

//	Receiving CoinSpark messages

	function coinspark_message_receive($txid, $testnet=false)
	{
	
	//	Retrieve the raw transaction content
	
		$transaction=coinspark_bitcoin_cli('getrawtransaction', $testnet, $txid, 1);
		if (!is_array(@$transaction['vout']))
			return array('error' => 'Could not retrieve transaction details');
	

	//	Extract the CoinSparkMessage if present
		
		$scripts=array();
		foreach ($transaction['vout'] as $vout)
			$scripts[]=$vout['scriptPubKey']['hex'];
			
		$metadata=CoinSparkScriptsToMetadata($scripts, true);
		if (!isset($metadata))
			return array('error' => 'This transaction does not have an OP_RETURN with metadata');
			
		$message=new CoinSparkMessage();
		if (!$message->decode($metadata, count($scripts)))
			return array('error' => 'The transaction does not contain a CoinSpark message');
			
		if (CONST_COINSPARK_DEBUG)
			echo $message->toString();
			
	
	//	Find if any of the message's intended outputs are mine
		
		$pubkey=null; // until we find one
		
		foreach ($transaction['vout'] as $vout) {
			if ($message->hasOutput($vout['n']))
				foreach ($vout['scriptPubKey']['addresses'] as $receive_address) {
					$result=coinspark_bitcoin_cli('validateaddress', $testnet, $receive_address);
					if (@$result['ismine'] && strlen(@$result['pubkey'])) {
						$pubkey=$result['pubkey'];
						break;
					}
				}
			
			if (isset($pubkey))
				break;
		}
		
		if (!isset($pubkey))
			return array('error' => 'The message in this transaction is not readable by you');
	
	
	//	First request to the messaging server to get nonce
	
		$server_url=$message->calcServerURL();
		$response=coinspark_json_rpc_call($server_url, 'coinspark_message_pre_retrieve', array(
			'testnet' => $testnet ? true : false,
			'txid' => $txid,
			'recipient' => $receive_address,
		));
		
		if (isset($response['error']))
			return array('error' => 'Failed to pre-retrieve message from '.$server_url.': '.@$response['error']['message']);
		
		$nonce=@$response['result']['nonce'];
		if (!strlen($nonce))
			return array('error' => 'No nonce was sent by the delivery server');
			
			
	//	Sign the request nonce
	
		$signature=coinspark_bitcoin_cli('signmessage', $testnet, $receive_address, $nonce);
		if (!is_string($signature))
			return array('error' => 'Failed to sign the nonce for delivery server');
			
	
	//	Retrieve the message from the delivery server
		
		$response=coinspark_json_rpc_call($server_url, 'coinspark_message_retrieve', array(
			'testnet' => $testnet ? true : false,
			'txid' => $txid,
			'recipient' => $receive_address,
			'nonce' => $nonce,
			'pubkey' => $pubkey,
			'signature' => $signature,
		));	
	
		if (isset($response['error']))
			return array('error' => 'Failed to retrieve message from '.$server_url.': '.@$response['error']['message']);

		$salt=base64_decode(@$response['result']['salt']);
		if (!strlen($salt))
			return array('error' => 'No salt was retrieved from the delivery server');
			
		if (!is_array($response['result']['message']))
			return array('error' => 'No message was retrieved from the delivery server');
	
	
	//	Check the message hash
			
		$messageParts=array();
		foreach ($response['result']['message'] as $part)
			$messageParts[]=array(
				'mimeType' => $part['mimetype'],
				'fileName' => $part['filename'],
				'content' => base64_decode($part['content']),
			);
			
		$checkHash=CoinSparkCalcMessageHash($salt, $messageParts);
		
		if (strncmp($message->hash, $checkHash, $message->hashLen))
			return array('error' => 'The message content does not match the hash in the OP_RETURN');
			
	
	//	Return the message if successful
		
		return array('message' => $messageParts);
	}
	

//	Parsing message delivery server URLs and talking to them

	function coinspark_parse_server_url($server_url, $host_max_len, $path_max_len)
	{
		$result=array();
		$parsed=parse_url($server_url);
		
	//	Disallowed parts
			
		if (@strlen($parsed['port'].$parsed['user'].$parsed['pass'].$parsed['query'].(@$parsed['fragment'])))
			return array('error' => 'Not allowed: port, username, password, query string, fragment id');
		
	//	URL scheme
		
		if ($parsed['scheme']=='https')
			$result['useHttps']=true;
		elseif ($parsed['scheme']=='http')
			$result['useHttps']=false;
		else
			return array('error' => 'Must be http:// or https://');
			
	//	Host/domain
			
		if (preg_match('/[^0-9A-za-z\-\.]/', $parsed['host']))
			return array('error' => 'Permitted host characters: 0-9 A-Z a-z - .');
			
		if (strlen($parsed['host'])>$host_max_len)
			return array('error' => 'Maximum length of hostname: '.(int)$host_max_len.' characters');
			
		$result['serverHost']=$parsed['host'];
			
	//	Path and optional prefix
			
		if (substr($parsed['path'], 0, 10)=='/coinspark') {
			$result['usePrefix']=true;
			$parsed['path']=substr($parsed['path'], 10);
		} else
			$result['usePrefix']=false;
		
		$result['serverPath']=trim($parsed['path'], '/');
		
		if (preg_match('/[^0-9a-z\-\.]/', $result['serverPath']))
			return array('error' => 'Permitted path characters: 0-9 a-z - .');

		if (strpos($result['serverPath'], '/')!==false)
			return array('error' => 'Only one path level permitted, apart from optional coinspark/ prefix');
		
		if (strlen($result['serverPath'])>$path_max_len)
			return array('error' => 'Maximum length of path: '.(int)$path_max_len.' characters');
			
	//	Return result if successful
	
		return $result;
	}
	
	function coinspark_json_rpc_call($url, $method, $params)
	{
		$request_id=time().'-'.rand(100000,999999);
		
		$request=array(
			'id' => $request_id,
			'method' => $method,
			'params' => $params,
		);
		
		$request_json=json_encode($request);
		
		if (CONST_COINSPARK_DEBUG)
			echo 'JSON-RPC: '.$url."\nRequest:".print_r(json_decode($request_json, true), true)."Response:";
		
		$curl=curl_init($url);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);	
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request_json);
		$response_json=curl_exec($curl);
		curl_close($curl);
		
		if (CONST_COINSPARK_DEBUG)
			echo print_r(json_decode($response_json, true), true)."\n";
		
		return json_decode($response_json, true);
	}
	
	
//	Talking to bitcoin-cli

	function coinspark_bitcoin_cli($command, $testnet) // more params are read from here
	{
		$command=CONST_BITCOIN_CMD.' '.($testnet ? '-testnet ' : '').escapeshellarg($command);
		
		$args=func_get_args();
		array_shift($args);
		array_shift($args);
		
		foreach ($args as $arg)
			$command.=' '.escapeshellarg(is_array($arg) ? json_encode($arg) : $arg);
		
		if (CONST_COINSPARK_DEBUG)
			echo $command."\n";
		
		$raw_result=rtrim(shell_exec($command), "\n");
		
		$result=json_decode($raw_result, true);
		
		if (CONST_COINSPARK_DEBUG)
			echo (isset($result) ? print_r(json_decode($raw_result, true), true) : ($raw_result."\n"))."\n";
		
		return isset($result) ? $result : $raw_result;
	}
	

//	Unpacking and packing bitcoin transactions	
	
	function coinspark_unpack_raw_txn($raw_txn_hex)
	{
		// see: https://en.bitcoin.it/wiki/Transactions
		
		$binary=pack('H*', $raw_txn_hex);
		
		$txn=array();
		
		$txn['version']=coinspark_string_shift_unpack($binary, 4, 'V'); // small-endian 32-bits

		for ($inputs=coinspark_string_shift_unpack_varint($binary); $inputs>0; $inputs--) {
			$input=array();
			
			$input['txid']=coinspark_string_shift_unpack($binary, 32, 'H*', true);
			$input['vout']=coinspark_string_shift_unpack($binary, 4, 'V');
			$length=coinspark_string_shift_unpack_varint($binary);
			$input['scriptSig']=coinspark_string_shift_unpack($binary, $length, 'H*');
			$input['sequence']=coinspark_string_shift_unpack($binary, 4, 'V');
			
			$txn['vin'][]=$input;
		}
		
		for ($outputs=coinspark_string_shift_unpack_varint($binary); $outputs>0; $outputs--) {
			$output=array();
			
			$output['value']=coinspark_string_shift_unpack_uint64($binary)/100000000;
			$length=coinspark_string_shift_unpack_varint($binary);
			$output['scriptPubKey']=coinspark_string_shift_unpack($binary, $length, 'H*');
			
			$txn['vout'][]=$output;
		}
		
		$txn['locktime']=coinspark_string_shift_unpack($binary, 4, 'V');
		
		if (strlen($binary))
			die('More data in transaction than expected');
		
		return $txn;
	}
	
	function coinspark_pack_raw_txn($txn)
	{
		$binary='';
		
		$binary.=pack('V', $txn['version']);
		
		$binary.=coinspark_pack_varint(count($txn['vin']));
		
		foreach ($txn['vin'] as $input) {
			$binary.=strrev(pack('H*', $input['txid']));
			$binary.=pack('V', $input['vout']);
			$binary.=coinspark_pack_varint(strlen($input['scriptSig'])/2); // divide by 2 because it is currently in hex
			$binary.=pack('H*', $input['scriptSig']);
			$binary.=pack('V', $input['sequence']);
		}
		
		$binary.=coinspark_pack_varint(count($txn['vout']));
		
		foreach ($txn['vout'] as $output) {
			$binary.=coinspark_pack_uint64(round($output['value']*100000000));
			$binary.=coinspark_pack_varint(strlen($output['scriptPubKey'])/2); // divide by 2 because it is currently in hex
			$binary.=pack('H*', $output['scriptPubKey']);
		}
		
		$binary.=pack('V', $txn['locktime']);
		
		return reset(unpack('H*', $binary));
	}
	
	function coinspark_string_shift(&$string, $chars)
	{
		$prefix=substr($string, 0, $chars);
		$string=substr($string, $chars);
		return $prefix;
	}
	
	function coinspark_string_shift_unpack(&$string, $chars, $format, $reverse=false)
	{
		$data=coinspark_string_shift($string, $chars);
		if ($reverse)
			$data=strrev($data);
		$unpack=unpack($format, $data);
		return reset($unpack);
	}
	
	function coinspark_string_shift_unpack_varint(&$string)
	{
		$value=coinspark_string_shift_unpack($string, 1, 'C');
		
		if ($value==0xFF)
			$value=coinspark_string_shift_unpack_uint64($string);
		elseif ($value==0xFE)
			$value=coinspark_string_shift_unpack($string, 4, 'V');
		elseif ($value==0xFD)
			$value=coinspark_string_shift_unpack($string, 2, 'v');
			
		return $value;
	}
	
	function coinspark_string_shift_unpack_uint64(&$string)
	{
		return coinspark_string_shift_unpack($string, 4, 'V')+(coinspark_string_shift_unpack($string, 4, 'V')*4294967296);
	}
	
	function coinspark_pack_varint($integer)
	{
		if ($integer>0xFFFFFFFF)
			$packed="\xFF".coinspark_pack_uint64($integer);
		elseif ($integer>0xFFFF)
			$packed="\xFE".pack('V', $integer);
		elseif ($integer>0xFC)
			$packed="\xFD".pack('v', $integer);
		else
			$packed=pack('C', $integer);
		
		return $packed;
	}
	
	function coinspark_pack_uint64($integer)
	{
		$upper=floor($integer/4294967296);
		$lower=$integer-$upper*4294967296;
		
		return pack('V', $lower).pack('V', $upper);
	}
	

//	Sort-by utility functions
	
	function coinspark_sort_by(&$array, $by1, $by2=null)
	{
		global $sort_by_1, $sort_by_2;
		
		$sort_by_1=$by1;
		$sort_by_2=$by2;
		
		uasort($array, 'coinspark_sort_by_fn');
	}

	function coinspark_sort_by_fn($a, $b)
	{
		global $sort_by_1, $sort_by_2;
		
		$compare=coinspark_sort_cmp($a[$sort_by_1], $b[$sort_by_1]);

		if (($compare==0) && $sort_by_2)
			$compare=coinspark_sort_cmp($a[$sort_by_2], $b[$sort_by_2]);

		return $compare;
	}

	function coinspark_sort_cmp($a, $b)
	{
		if (is_numeric($a) && is_numeric($b)) // straight subtraction won't work for floating bits
			return ($a==$b) ? 0 : (($a<$b) ? -1 : 1);
		else
			return strcasecmp($a, $b); // doesn't do UTF-8 right but it will do for now
	}
