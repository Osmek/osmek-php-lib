<?php 

/*
Osmek.php
Author: Osmek LLC, http://osmek.com
*/

class Osmek {
	
	/* ---------------------------------------------------------------------
	* Account API Key
	* Set your account API key. You can find this in the "My Account" 
	* section of Osmek.
	*/
	var $account_api_key	=	'';
	
	/* ---------------------------------------------------------------------
	* API Version
	* Set the API version you want to access
	*/
	var $api_version	= '3.1.3';
	
	/* ---------------------------------------------------------------------
	* Account ID
	* load_account will populate this
	*/
	var $account_id			=	false;
	
	/* ---------------------------------------------------------------------
	* Section IDs
	* You can store section ids here for retrieval in your script.
	* load_account will populate this
	*/
	var $section_ids		= array();
	

	/* ---------------------------------------------------------------------
	* Feed Caching
	* You can cache feeds from Osmek to improve your site's performance and 
	* protect you in the event of and Osmek outage.
	*/
	
	// Choose whether or not to cache feeds.
	var $cache_feeds = false;
	
	// Location of your cache folder. Must be writable. (With trailing slash!)
	var $cache_folder = './cache/';
	
	// How often should the cache be refreshed? (In seconds)
	var $cache_time	 = 1200;
	
	// Choose whether or not to cache images.
	var $cache_images = false;
	
	// Location of your cache folder. Must be writable.
	var $cache_images_folder =	'';
	
	// URI of new images. This is usually your domain name. 
	// (With trailing slash!)
	var $cache_images_uri =	'';	
	
	
	////////////////////////////////////////////////////////////////////////
	// ! Stop Editing !
	////////////////////////////////////////////////////////////////////////
	
	
	/* API location (With Trailing Slash!) */
	var $api_server			=	'http://api.osmek.com/';
	
	/* Did the API return a header of Osmek-Status: ok  */
	var $osmek_status_ok	=	'unknown';
	
	/* Sections array. Stores section info if load_account() is called */
	var $sections			=	array();
	
	/* Var to hold messages */
	var $msg				=	'';
	
	/* How long to wait for Osmek response (in seconds). After this, cache will be used. */
	var $timeout_length		=	3;
	
	/* Have we experienced an Osmek timeout ? */
	var $have_timeout		=	false;
	
	/* Headers from the last call */
	var $last_headers		=	array();
	
	/* Write Errors ? */
	var $error_log			=	true;
	
	/* Error log location */
	var $error_log_file		=	'./cache/error_log.txt';
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Constructor.
	 * You can pass an array of config data to the class.
	 * 
	 */
	function Osmek($config = false)
	{
		
		/*
		*	$config can be an array of data, or a string API key
		*/
		if(is_array($config))
		{
			foreach($config as $key => $val)
			{
				if(isset($this->$key)) $this->$key = $val;
			}
		}
		else if(is_string($config)) 
		{
			$this->account_api_key = $config;
		}
		
		/*
		*	If we're caching feeds, make sure the directories are in place
		*/
		if($this->cache_feeds)
		{
			// Create Directory
			if( ! @file_exists($this->cache_folder)) @mkdir($this->cache_folder, 0777);
			
			// Make it writable
			if( ! @is_writable($this->cache_folder)) @chmod($this->cache_folder, 0777);
		}
		
		/*
		*	Add api version to server url
		*/
		if($this->api_version)
		{
			$this->api_server .= trim($this->api_version, ' /');
		}
	}
		
	
	
	// --------------------------------------------------------------------
	
	/* ! Core Methods */
		
	/**
	 * Call Method
	 * Post data to Osmek and return response.
	 * 
	 */
	function call_method($method, $data = array())
	{
		/*
		*	Make sure api_key is set
		*/
		if( ! isset($data['api_key'])) $data['api_key'] = $this->account_api_key;
		
		/*
		*	if section_id is not a number, see if the id is saved in $this->section_ids
		*/
		if(isset($data['section_id']) && ! is_int($data['section_id']) && ! ctype_digit($data['section_id']) && isset($this->section_ids[$data['section_id']]))
		{
			$data['section_id'] = $this->section_ids[$data['section_id']];
		}
			
		/*
		*	Build the POST string
		*/	
		$post_query = '';
		foreach($data as $key => $value) $post_query .= $key.'='.urlencode($value).'&';
		$post_query = trim($post_query, ' &');
		
		/*
		*	Make the call
		*/
		$url = parse_url($this->api_server);
		$host = $url['host'];
		$path = $url['path'].$method; 
		$port = $url['scheme'] == 'https' ? '443' : '80';
		$headers = array();
		$flag = false; 	// When headers are done sending
		$response = '';
		
		/*
		*	This could be done with CURL but we're using fsockopen for compatability in case CURL isn't installed
		*/
		$fp = fsockopen($host, $port, $errno, $errstr, $this->timeout_length); 
		if($fp)
		{ 
			$out = "POST $path HTTP/1.0\r\n"
					. "Host: $host\r\n"
					. "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n"
					. "Content-length: ". strlen($post_query) ."\r\n"
					. "Connection: Close\r\n\r\n"
					. $post_query . "\r\n";
			
			if(fwrite($fp, $out) === false) return false;
			
			stream_set_timeout($fp, $this->timeout_length, 0);
			$info = stream_get_meta_data($fp);
			
			while ( ! feof($fp) && ! $info['timed_out'])
			{ 
				$line = fgets($fp, 10240); 
			    if( ! $flag)
			    {
			    	if (strlen(trim($line)) == 0)
					{
						$flag = true;
					}
					else
					{
						$header_name = count($headers);
				    	$header_value = trim($line);
				    	if(strpos($line, ':') !== false)
				    	{
				    		$header_name = trim(substr($line, 0, strpos($line, ':')));
				    		$header_value = trim(substr($line, strpos($line, ':')+1));
				    	}
						$headers[$header_name] = $header_value;
					}
			    }
			    else
			    {
					$response .= $line; 
				} 
				
				$info = stream_get_meta_data($fp);
			} 
			
			fclose($fp);
			
			$this->last_headers = $headers;
			
			if($info['timed_out'])
			{
				$this->log_error('READ TIMEOUT: ['.$this->api_server.'], query: '.$post_query);
				$this->osmek_status_ok = false;
				$this->have_timeout = true;
				$response = false;
			}
		}
		else
		{
			// Connection time out.
			$this->log_error('CONNECT TIMEOUT: ['.$this->api_server.'], query: '.$post_query);
			$this->osmek_status_ok = false;
			$this->have_timeout = true;
		}
		
		/*
		*	Did Osmek return a status header?
		*/
		$this->osmek_status_ok = (isset($headers['Osmek-Status']) && $headers['Osmek-Status'] == 'ok') ? true : false;
		
		if($this->osmek_status_ok === false && $this->have_timeout === false)
		{
			$this->log_error('OSMEK STATUS "FAIL": ['.$this->api_server.'], query: '.$post_query);
		}
		
		/*
		*	Return response
		*/
		return $response;
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch Feed
	 * Caching wrapper for read only feeds
	 */
	function fetch_feed($data = array(), $force_cache = 'default')
	{
		
		/*
		*	If method wasn't included in the $data array, assume it's "feed"	
		*/
		$method = isset($data['method']) ? $data['method'] : 'feed';
		
		/*
		*	Caching
		*	If "default" check the local var
		*/
		$cache = $force_cache == 'default' ? $this->cache_feeds : $force_cache;
		if(is_int($force_cache))
		{
			$this->cache_time = $force_cache;
			$cache = true;
		}
		
		/*
		*	Figure out cache location
		*/
		$cache_file = md5(implode(',', $data)).".txt";
		
		// Prefix cach file with section id so we can clear section specific caches
		if(isset($data['section_id']))
		{
			// Check for section id value in $this->section_ids array
			$section_id = isset($this->section_ids[$data['section_id']]) ? $this->section_ids[$data['section_id']] : $data['section_id'];
			$cache_file = $section_id.'-'.$cache_file;
		}
		
		$cache_path = $this->cache_folder.$cache_file;
		
		/* If Osmek isn't responding during this session, resort to cache */
		if ($this->have_timeout || $this->osmek_status_ok === false)
		{
			if(@file_exists($cache_path))
			{
				$response = $this->read_cache($cache_path);
			}
			else
			{
				$response = false;
			}
		}
		else if ($cache && @file_exists($cache_path) && (( @filemtime($cache_path) + $this->cache_time ) > time()))
		{
			// We're caching, and cache file is still good. Read the cache!
			$response = $this->read_cache($cache_path);
			
			// If the cache couldn't be read for some reason, start over and ignore cache
			if($response === false)
			{
				return $this->fetch_feed($data, false);
			}
		}
		else
		{
			// Get feed from Osmek
			$response = $this->call_method($method, $data);
			
			// Is the response valid? If so, write the cache
			if($this->osmek_status_ok)
			{
				$this->write_cache($cache_path, $response);
			}
			
			// if an error occured, & we have a cache file for this feed revert to cache
			else if(@file_exists($cache_path))
			{
				$response = $this->read_cache($cache_path);
			}
		}
		
		/*
		*	Pass data through prep_data_out, and return.
		*/
		return $this->prep_data_out($response);
	}
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Prep data out
	 * Run data through filters before sending out
	 * 
	 */
	function prep_data_out($data, $type = false)
	{		
		// should we cache images?
		if($this->cache_images)
		{
			$data = $this->save_images($data);
		}
		
		return $data;
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Write error log
	 * 
	 */
	function log_error($err)
	{
		$err = '['.date('r').'] - '.$err;
		@file_put_contents($this->error_log_file, $err, FILE_APPEND | LOCK_EX);
	}
	

	
	
	// --------------------------------------------------------------------
		
	/* ! Caching */
	
	/**
	 * Caching
	 * 
	 */
	function read_cache($path)
	{
		if( ! file_exists($path)) return false;
		
		$fp = fopen($path, 'r');
		
		if($fp === false)
		{
			return false;
		}
		else
		{
			$response = '';
			while ( ! feof($fp))
			$response .= fread($fp, 4000);
			fclose($fp);
			return $response;
		}
	}
	
	function write_cache($path, $data)
	{
		$fp = @fopen($path, "w+");
		@fwrite($fp, $data);
		@fclose($fp);
	}
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Clear cache, and display notice
	 * 
	 */
	function clear_cache()
	{
		$msg = '<div style="margin:40px;padding:20px;background:#efefef;border:1px solid #ccc;width:500px;margin-left:auto;margin-right:auto;text-align:center;">[msg]</div>';
		
		$cache_path = $this->cache_folder;
		$handle = @opendir($cache_path);
		
		if($handle === false)
		{
			echo str_replace('[msg]', 'Could not open cache folder "'.$cache_path.'"', $msg);
			return false;
		}
		
		$filescleared = 0;
		$fileserror = 0;
		while (false !== ($file = readdir($handle))) {
			if ($file != "." && $file != "..") {
				$cleared = @unlink($cache_path.'/'.$file);
				
				if($cleared){
					$filescleared++;
				}else{
					$fileserror++;
				}
			}
		}
		$cleared = "$filescleared files were cleared<br>";
		if($fileserror > 0) $cleared .= "$fileserror files could not be cleared";
		
		echo str_replace('[msg]', $cleared, $msg);
		closedir($handle);
	}
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Used for Osmek pingback notifications to clear cache for a specific
	 * section
	 * 
	 */
	function clear_section_cache($section_id = false)
	{
		$cache_path = $this->cache_folder;
		$handle = @opendir($cache_path);
		
		if($handle === false) return false;
		
		$filescleared = 0;
		$fileserror = 0;
		while (false !== ($file = readdir($handle))) {
			if ($file != "." && $file != "..") {
				if(substr($file, 0, strlen($section_id)+1) == $section_id.'-')
				{
					$cleared = @unlink($cache_path.'/'.$file);
				}
				
				if($cleared){
					$filescleared++;
				}else{
					$fileserror++;
				}
			}
		}
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Save Images
	 * Try to parse images from response, save them locally, then update the
	 * response, replacing image urls with local urls
	 * 
	 */
	function save_images($data)
	{
		// If it's an array, save for each member, recursively.
		if(is_array($data))
		{
			foreach($data as $key => $value) $data[$key] = $this->save_images($value);			
			return $data;
		}
		
		
		// Parse image urls out of the string, save the image, and replace them with new urls
		else if(is_string($data))
		{
			$data = stripslashes($data);
			$match = preg_match_all('#http://(images|photos).osmek.com/get/[0-9]{2,11}\.?([a-zA-Z0-9_]*)?\.?(jpg|png|gif)?#', $data, $matches, PREG_SET_ORDER);
			if($match)
			{
				foreach($matches as $m)
				{
					$url = $m[0];
					$data = str_replace($url, $this->save_image($url), $data);
				}
			}
			return $data;
		}		
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Save image
	 * 
	 */
	function save_image($url)
	{
		$imgurl = $url;
		$filename = substr($imgurl, strrpos($imgurl, "/") + 1);
		$ds = $this->cache_images_folder.$filename;
		
		if( ! file_exists($ds))
		{
			$ch = curl_init($imgurl);
			$fp = fopen($ds, 'w');
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_exec($ch);
			$curl_info =  curl_getinfo($ch);
			curl_close($ch);
			fclose($fp);
		}
		
		return $this->cache_images_uri.$filename;
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Clear image cache
	 * 
	 */
	function clear_image_cache()
	{
		// Images
		if($this->cache_images)
		{
			$msg = '<div style="margin:40px;padding:20px;background:#efefef;border:1px solid #ccc;width:500px;margin-left:auto;margin-right:auto;text-align:center;">[msg]</div>';
		
			$cache_path = $this->cache_images_folder;
			$handle = @opendir($cache_path);
			
			if($handle === false)
			{
				echo str_replace('[msg]', 'Could not open cache folder "'.$cache_path.'"', $msg);
				return;
			}
			
			$filescleared = 0;
			$fileserror = 0;
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != "..") {
					$cleared = @unlink($cache_path.'/'.$file);
					
					if($cleared){
						$filescleared++;
					}else{
						$fileserror++;
					}
				}
			}
			$cleared = "$filescleared images were deleted<br>";
			if($fileserror > 0) $cleared .= "$fileserror images could not be deleted";
			
			echo str_replace('[msg]', $cleared, $msg);
			closedir($handle);
		}
	}
	
	
	
	
	
	
	
	/* ! Convenience Methods */
	
	/*
	* These above methods are all you need but these make life a little easier!
	* Just wrappers for call_method and fetch_feed
	*/
	
	// --------------------------------------------------------------------
		
	/**
	 * Load Account
	 * Call account_info and populate local vars with response
	 * 
	 */
	function load_account()
	{
		if( ! $this->account_api_key) return false;
		
		$account = json_decode($this->account_info());
		
		if( ! $account OR isset($account->status) && $account->status != 'ok')
		{
			$this->msg = isset($account->msg) ? $account->msg : '';
			return false;
		}
		
		$this->account_id = $account->id;
		$this->sections = $account->sections;
		foreach($account->sections as $section)
		{
			if($section->slug) $this->section_ids[$section->slug] = $section->id;
		}
		
		return true;
	}
	
	// --------------------------------------------------------------------
		
	/**
	 * Account Info
	 * 
	 */
	function account_info($data = array(), $force_cache = 'default')
	{
		$base = array(
			'method' => 'feed/account_info',
			'format' => 'json'
		);
		
		$data = array_merge($base, $data);
				
		return $this->fetch_feed($data, $force_cache);		
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Section Info
	 * 
	 */
	function section_info($sect_id, $data = array(), $force_cache = 'default')
	{
		$base = array(
			"method" => 'feed/section_info',
			"section_id" => $sect_id,
			"format" => "json"
		);
		
		$data = array_merge($base, $data);
				
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch Template
	 * 
	 */
	function fetch_template($sect_id, $template, $options = array(), $force_cache = 'default')
	{
		$data = array(
			'section_id' => $sect_id,
			'template' => $template
		);
		
		$data = array_merge($options, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch HTML
	 * 
	 */
	function fetch_html($sect_id, $options = array(), $force_cache = 'default')
	{
		$data = array(
			"section_id" => $sect_id,
			"format" => "html"
		);
		
		$data = array_merge($options, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch XML
	 * 
	 */
	function fetch_xml($sect_id, $options = array(), $force_cache = 'default')
	{
		$data = array(
			"section_id" => $sect_id,
			"format" => "xml"
		);
		
		$data = array_merge($options, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch JSON
	 * 
	 */
	function fetch_json($sect_id, $options = array(), $force_cache = 'default')
	{
		$data = array(
			"section_id" => $sect_id,
			"format" => "json"
		);
		
		if(isset($options['vars']) && is_array($options['vars']))
		{
			$options['vars'] = implode('|', $options['vars']);
		}
		
		$data = array_merge($options, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch PHP
	 * 
	 */
	function fetch_array($sect_id, $options = array(), $force_cache = 'default')
	{
		$data = array(
			"section_id" => $sect_id,
			"format" => "php"
		);
		
		$data = array_merge($options, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}	
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch Vars
	 * 
	 * Fetches an array of variables from an osmek feed with format = template.
	 * If limit is set to 1, an associative array is retuned
	 * If more than one entry is returned in the feed, an indexed array will be returned.
	 *
	 */
	function fetch_vars($sect_id, $vars, $options = array(), $force_cache = 'default', $associative_array = false)
	{
		$data = array(
			'section_id' => $sect_id
		);
		
		$data = array_merge($data, $options);
		
		$del_item = "ITEM_DELIMITER";
		$del_and = "{OSMEK_AND}";
		$del_equels = "{OSMEK_EQUELS}";
		
		// Create a template based on the vars requested
		$template = $del_item;
		foreach($vars as $key => $value)
		{
			// If key is a string, assume we want to name the variable by that key name
			if(is_string($key))
			{
				$varname = $key;
			}
			else
			{
				$varname = $value;
				
				// If value has a space, use the first word as the var name.
				if(strpos($varname, ' ') !== false) $varname = substr($varname, 0, strpos($varname, ' '));
			}
						
			$template .= $del_and.$varname.$del_equels."[".$value."]";
		}
		
		$data['template'] = $template;
			
		$rsp = $this->fetch_feed($data, $force_cache);
		
		/*
		*	If response if not valid, return an empty array
		*/
		if($rsp == false)
		{
			return array();
		}
		
		/*
		*	If response IS valid, parse it into an array
		*/
		$vars = array();
		
		$items = explode($del_item, $rsp);
		
		foreach($items as $item)
		{
			if($item)
			{
				$varpairs = explode($del_and, $item);
				
				$itemvars = $associative_array ? array() : new stdClass();
				
				foreach($varpairs as $varpair)
				{
					$varpair = explode($del_equels, $varpair);
					if($varpair[0])
					{
						$varname = $varpair[0];
						if( ! $associative_array)
						{
							$itemvars->$varname = $varpair[1];
						}
						else
						{
							$itemvars[$varname] = $varpair[1];
						}
					}
				}
				array_push($vars, $itemvars);
			}
		}
		
		/*
		*	If limit was set, return associative array
		*/
		if((isset($options['limit']) && $options['limit'] == 1))
		{
			$vars = $vars[0];
		}
		
		return $vars;
	}
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Get Comments
	 * 
	 */
	function fetch_comments($sect_id, $item_id, $data = array(), $force_cache = 'default')
	{
		$base = array(
			'section_id' => $sect_id,
			'item_id' => $item_id,
			'method' => 'feed/comments'
		);
		$data = array_merge($base, $data);
		
		return $this->fetch_feed($data, $force_cache);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Fetch Comments Array
	 * 
	 */
	function fetch_comment_vars($sect_id, $item_id, $vars = array(), $data = array(), $force_cache = 'default')
	{
		$base = array(
			'item_id' => $item_id,
			'method' => 'feed/comments'
		);
		$data = array_merge($base, $data);
		
		return $this->fetch_vars($sect_id, $vars, $data, $force_cache);	
	}
	
	
	
	
	/* ! Write Methods */
	
	// --------------------------------------------------------------------
		
	/**
	 * Create an item
	 * 
	 */
	function create($sect_id, $data)
	{
		$data['section_id'] = $sect_id;		
		return $this->call_method('create', $data);
	}

	
	
	// --------------------------------------------------------------------
		
	/**
	 * Update an item
	 * 
	 */
	function update($sect_id, $item_id, $data)
	{
		$data['section_id'] = $sect_id;
		$data['item_id'] = $item_id;
		
		return $this->call_method('update', $data);
	}
	
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * New Contact
	 * Add a contact to a "contacts" section
	 * 
	 */
	function new_contact($sect_id, $data)
	{
		// If it's not an array, it's probably just an email address
		if( ! is_array($data))
		{
			$data = array('email' => $data);
		}
		$data['section_id'] = $sect_id;		
		return $this->call_method('new_contact', $data);
	}
	
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Make a comment
	 * 
	 */
	function make_comment($sect_id, $item_id = 0, $comment = array(), $options = array())
	{
		$data = array(
			'section_id' => $sect_id,
			'item_id' => $item_id,
			'user_ip' => ($_SERVER['REMOTE_ADDR'] != getenv('SERVER_ADDR')) ? $_SERVER['REMOTE_ADDR'] : getenv('HTTP_X_FORWARDED_FOR'),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
			'user_referrer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false
		);
		
		$data = array_merge($data, $comment, $options);
				
		$rsp = $this->call_method('make_comment', $data);
		return $rsp;
	}
	
	
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Subscribe an email address to a section
	 * 
	 */
	function subscribe($sect_id, $email)
	{
		$data = array(
			'email' => $email, 
			'section_id' => $sect_id
		);
		
		return $this->call_method('subscribe', $data);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Check Login
	 * 
	 */
	function check_login($account_id, $username, $password, $extra = array())
	{
		$data = array(
			'username' => $username,
			'password' => md5($password.$account_id)
		);
		
		$data = array_merge($extra, $data);
		
		return $this->call_method('check_login', $data);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Submit a log entry to Osmek
	 * 
	 */
	function log($data)
	{		
		return $this->call_method('log', $data);
	}
	
	
	// --------------------------------------------------------------------
		
	/**
	 * Upload a photo
	 * 
	 */
	function upload_photo($file_name, $tmp_name, $data = array())
	{
		$post = array_merge(array(
			'api_key' => $this->account_api_key,
			'file_name' => $file_name,
			'Filedata' => "@".$tmp_name
		), $data);
		
		$ch = curl_init();
	    curl_setopt($ch, CURLOPT_HEADER, 0);
	    curl_setopt($ch, CURLOPT_VERBOSE, 0);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
	    curl_setopt($ch, CURLOPT_URL, 'http://api.osmek.com/upload_photo');
	    curl_setopt($ch, CURLOPT_POST, true);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $post); 
	    $response = curl_exec($ch);
	    
	    return $response;
	}
	

}




/* End of file Osmek.php */
/* Location: ./application/libraries/osmek.php */