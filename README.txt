coinspark-message v1.0

A simple PHP script to send and receive bitcoin transactions with CoinSpark messages

Copyright (c) Coin Sciences Ltd - http://coinspark.org/

MIT License (see headers in files)


REQUIREMENTS:

* Unix-based operating system, e.g. Linux or Mac OS X
* PHP 5.x or later
* bitcoin-cli installed (does not use JSON-RPC)
* Must be run as a user who is permitted to run bitcoin-cli


SENDING MESSAGES - USAGE ON THE COMMAND LINE:

* Ensure CONST_BITCOIN_CMD and CONST_BITCOIN_FEE in php-OP_RETURN.php are correct and check the other settings.

* php send-coinspark-message.php <send-address> <send-amount> <message-text> <testnet (optional)>

- <send-address> is the bitcoin address of the recipient
- <send_amount> is the amount to send (in units of BTC)
- <message-text> is a UTF-8 string containing the message to be sent with the transaction
- <testnet> should be 1 to use the bitcoin testnet, otherwise it can be omitted

- Outputs an error if one occurred or the txid if sending was successful

* Examples:

php send-coinspark-message.php 149wHUMa41Xm2jnZtqgRx94uGbZD9kPXnS 0.001 'Here is some bitcoin for you.'
php send-coinspark-message.php mzEJxCrdva57shpv62udriBBgMECmaPce4 0.001 'Here is some testnet bitcoin for you!' 1

* Wait a few seconds then check http://coinsecrets.org/ to see your transaction with CoinSpark OP_RETURN.



RECEIVING MESSAGES - USAGE ON THE COMMAND LINE:

* Ensure CONST_BITCOIN_CMD in php-OP_RETURN.php is correct and check the other settings.

* php receive-coinspark-message.php <txid> <testnet (optional)>

- <txid> is the hexadecimal transaction ID of the transaction to be checked
- <testnet> should be 1 to use the bitcoin testnet, otherwise it can be omitted

- Outputs an error if one occurred or the message if it was successfully retrieved. If the message is
  one piece of plain text without a filename (as created by this library) then that text will be shown.
  Otherwise the full array of message parts will be shown, with MIME types and filenames.

* Examples:

php receive-coinspark-message.php bdb7b22c5a529a4feff994f8886123c793113a0c89d61fa72ffa0d3276a690df
php receive-coinspark-message.php 3c62230aa2022b4213a72782b269b4a9e9fdb4965ca345cdf830a2f3aeccafad 1



SENDING MESSAGES - USAGE AS A LIBRARY:

* Ensure CONST_BITCOIN_CMD and CONST_BITCOIN_FEE in php-OP_RETURN.php are correct and check the other settings.

* Include/require 'coinspark-message.php' in another script.

* coinspark_message_send($send_address, $send_amount, $message_text, $testnet=false)

- $send_address is the bitcoin address of the recipient
- $send_amount is the amount to send (in units of BTC)
- $message_text a UTF-8 string containing the message to be sent with the transaction
- $testnet is true/false whether to use the bitcoin testnet

- Returns: array('error' => '[some error string]') OR array('txid' => '[sent txid]')

* Wait a few seconds then check http://coinsecrets.org/ to see your transaction with CoinSpark OP_RETURN.



RECEIVING MESSAGES - USAGE AS A LIBRARY:

* Ensure CONST_BITCOIN_CMD in php-OP_RETURN.php is correct and check the other settings.

* Include/require 'coinspark-message.php' in another script.

* coinspark_message_receive($txid, $testnet=false)

- $txid is the hexadecimal transaction ID of the transaction to be checked
- $testnet is true/false whether to use the bitcoin testnet

- Returns: array('error' => '[some error string]') OR array('message' => full message parts array)



WHY NO WINDOWS SUPPORT?

There is an issue on Windows with the escapeshellarg() PHP function. A suitable
replacement is required which escapes shell argumentes safely and effectively.
The command line execution of bitcoin-cli could also be replaced by JSON-RPC calls.


VERSION HISTORY

v1.0 - 22 February 2015
* First release
