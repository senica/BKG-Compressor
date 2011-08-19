<?php
/************************************************************************
* BKG Compressor
* This software is released under the GNU General Public License
* http://www.gnu.org/copyleft/gpl.html
* Leave this header in tact when using this
* 
* Developed by Senica Gonzalez senica@gmail.com
* Allebrum, LLC www.allebrum.com
*
* Standalone PHP Compressor and DeCompressor.
* 
* This Class compresses a specified directory or file using the
* byte-pair algorithm described here:
* http://en.wikipedia.org/wiki/Byte_pair_encoding
*
* Compression is around 2:1 which is not great, but the purpose of this
* class is to provide an independent means for compressing and
* decompressing files without the need to install external PHP Libraries.
*
* Does not include the specified input directory.
*
* Usage:
* $bkg = new BKG();
* $bkg->compress("absolute path to directory", "output.pkg", "comma 
*	separated list of exclusions");
* $bkg->inflate("output.pkg");
*
* A single wildcard at the beginning of the word in the exclusions
* list may be used to signify that you want to exclude the Name wherever
* it is found.  Otherwise, you MUST use the full relative path.
*
* Compression is best done from the command line.  If the compression
* freezes from the browser, it will peg your CPU.
*
* Testing revealed that files of 50MB or larger would hang the process.
*
* Package Format (if you want to build your own uncompressor):
* A pointer should be used and moved the number of bytes you have read from.
* Continue to do this until the end of the package.
* Last 4 bytes are the checksum.  It MUST be the length of the file minus the checksum 4 bytes.
* After verifying checksum, pull it off before processing.
* 1. Type						2 bytes
*									0a00 - File
*									0a01 - Directory
* 2. Length of Filename			2 bytes
* 3. Filename					Variable length specified by Length of Filename
* 
* 4. If type is 0a01, create the directory with Filename. Return to step 1.
*    If type is 0a00, continue to get file content
* 
* 5. Content Size				4 bytes
* 6. Content					Variable length specified by Content Size
* 7. Number of Dictionaries		1 byte
* 8. Dictionary Length			2 bytes
* 9. Dictionary Content		Variable Length specified by Dictionary Length
*
* 10. At this point the Dictionary Content should be broken up into 3 byte
*     sections.  The 1st byte is the byte that is in the Content, the 2nd
*     byte is the byte that should replace it. Do a search and replace for
*     all occurences of the 1st byte with the second byte. Hold the Content.
*
* 11. If the Number of Dictionaries is greater than 1, return to step 8.
*
* 12. Write the file with Filename
* 13. Return to step 1.
************************************************************************/
set_time_limit(0);

class BKG{
	
	var $content, $table, $pass, $compression, $output, $exclude, $trial;
	
	function __construct(){
		$this->pass		= 0;		//Record of passes through a file.
		$this->table	= array();	//Dictionary List.  Formed in $this->shrink();
		$this->exclude	= array();	//Exclusions List.  Formed in $this->compress();
		$this->trial	= false;	//Not used, only here for troubleshooting
	}
	
	/*********************************************
	* Get any Bytes that are not used in a file
	* These will be used as placeholders for the
	* dictionary.
	*********************************************/
	private function build_table(){
		$this->table[$this->pass] = array();
		for($i=0; $i<256; $i++){
			$byte = pack("H*", sprintf("%02X", $i));
			if(!strstr($this->content, $byte)){
				$this->table[$this->pass][bin2hex($byte)] = '';
			}
		}	
	}
	
	/*********************************************
	* Builds the dictionary
	*********************************************/
	private function table_insert($value){
		$key = key($this->table[$this->pass]);
		$this->table[$this->pass][$key] = $value;
		return (next($this->table[$this->pass]) === false) ? false : $key;
	}
	
	/*********************************************
	* Removes any unused keys in the dictionary
	* to save space.
	*********************************************/
	private function table_close(){
		while(end($this->table[$this->pass]) == ''){
			if(empty($this->table[$this->pass])){ unset($this->table[$this->pass]); break; }
			array_pop($this->table[$this->pass]);
		}
	}
	
	/*********************************************
	* Do the actual shrinking of the file.
	* Create a temp table, then get the highest
	* matched byte pairs and build the real
	* table with those
	*********************************************/
	private function shrink(){
		$this->build_table();
		//Build Temp Table for Highest Counts
		$hold = $this->content;
		$hold_table = array();
		for($i=0; $i<strlen($hold); $i++){
			$t = substr($hold, $i, 2);
			$count = substr_count($hold, $t);
			if($count > 1){
				$hold = str_replace($t, '', $hold);
				$hold_table[bin2hex($t)] = $count;
			}
		}
		//Build Real Table		
		$run = true;
		while($run){
			if(empty($hold_table)){ break; }
			$keys = array_keys($hold_table, max($hold_table)); //Get highest values
			foreach($keys as $v){ //Replace hightest values				
				unset($hold_table[$v]);
				$r = $this->table_insert($v); //Insert into table and get replacement value				
				if($r === false){ $run = false; break; }else{
					$pack = pack("H*", str_pad($r, 2, 0, STR_PAD_LEFT));
					$value = pack("H*", str_pad($v, 4, 0, STR_PAD_LEFT));
					$this->content = str_replace($value, $pack, $this->content); //Do byte swapping
				}
			}
		}
		$this->table_close();
		$this->pass++;
		if($this->pass < $this->compression){ $this->shrink(); } //Do another pass
	}
	
	/*********************************************
	* Run through all directories and files
	* of a specified directory
	*********************************************/
	private function traverse($dir, $relative){
		if ($handle = opendir($dir)) {
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != "..") {					
					if(in_array($relative.'/'.$file, $this->exclude) || in_array("./*".$file, $this->exclude)){
								//Exclude and don't traverse
					}else{
						echo $relative.'/'.$file."\r\n";
						if(is_dir($dir.'/'.$file)){
							file_put_contents($this->output, pack("H*", "0A01").pack("H*", sprintf("%04X", strlen($relative.'/'.$file))).$relative.'/'.$file, FILE_APPEND);
							$this->traverse($dir.'/'.$file, $relative.'/'.$file);
						}else{
							$this->content = file_get_contents($dir.'/'.$file);
							$this->table = array();
							$this->pass = 0;
							file_put_contents($this->output, pack("H*", "0A00").pack("H*", sprintf("%04X", strlen($relative.'/'.$file))).$relative.'/'.$file, FILE_APPEND);
							$this->shrink();
							$this->write_zip();	
						}
					}
				}
			}
			closedir($handle);
		}		
	}
	
	/*********************************************
	* This is the compress function to be
	* called.  Usage described in header
	*********************************************/
	function compress($file=false, $output=false, $exclude=false, $compression=4){
		$pieces = explode(',', $exclude);
		foreach($pieces as $p){
			$p = trim($p);
			if(substr($p, 0, 2) != './'){ $p = './'.$p; }	
			array_push($this->exclude, $p);
		}
		$this->output = ($output === false) ? (uniqid().'.pkg') : $output;
		$this->compression = $compression;
		if(file_exists($output)){ unlink($output); }
		if(is_dir($file)){
			$this->traverse($file, '.');
		}else if(is_file($file)){
			$this->content = file_get_contents($file);
			file_put_contents($this->output, pack("H*", "0A00").pack("H*", sprintf("%04X", strlen(basename($file)))).basename($file), FILE_APPEND);
			$this->shrink();
			$this->write_zip();
		}
		//Add checksum
		file_put_contents($this->output, pack("H*", sprintf("%08X", filesize($this->output))), FILE_APPEND);
	}
	
	/*********************************************
	* Run through the dictionary and write the
	* package file.
	*********************************************/
	private function write_zip(){
		$hold = '';
		$hold .= pack("H*", sprintf("%08X", strlen($this->content))).$this->content; //2byte content size, then content
		$hold .= pack("H*", sprintf("%02X", sizeof($this->table))); //Byte1 - Number of Passes Also number of Table Arrays
		$tables = array_reverse($this->table);
		foreach($tables as $table){
			$thold = '';
			foreach($table as $k=>$v){
				$table = array_reverse($table); //Maybe?
				$thold .= pack("H*", str_pad($k, 2, 0, STR_PAD_LEFT));	//1byte Replaced value //May need to pad these str_pad($value, 4, "0", STR_PAD_LEFT);
				$thold .= pack("H*", str_pad($v, 4, 0, STR_PAD_LEFT));	//2bytes Original Value May need to be padded
			}
			$hold .= pack("H*", sprintf("%04X", strlen($thold))); //2 Byte length of dictionary
			$hold .= $thold; //Add the dictionary
		}
		file_put_contents($this->output, $hold, FILE_APPEND);
	}
	
	/*********************************************
	* Get decimal value
	*********************************************/
	private function dec($value){
		$value = base_convert(bin2hex($value), 16, 10);
		return $value;
	}
	
	/*********************************************
	* Recreate ascii from binary hex string
	*********************************************/
	private function hex2bin($data) {
		$len = strlen($data);
		$newdata = '';
		for($i=0;$i<$len;$i+=2) {
			$newdata .= pack("C",hexdec(substr($data,$i,2)));
		}
	   return $newdata;
	}
	
	/*********************************************
	* Uncompress function
	*********************************************/
	function inflate($package){
		if(!file_exists($package)){ return false; }
		$content = file_get_contents($package);
		$pointer = 0;
		$checksum = $this->dec(substr($content, -4));
		$realsize = filesize($package) - 4; //Minus the checksum
		echo "Checksum: ".$checksum." bytes\r\n";
		echo "Actual Filesize: ".$realsize." bytes\r\n";
		if($realsize != $checksum){ echo "Malformed Package.  Exited\r\n"; return false; }
		else{
			$content = substr($content, 0, strlen($content)-4); //Remove the checksum
			while($pointer < strlen($content)){
				$type	= bin2hex(substr($content, $pointer, 2)); $pointer+=2; //0a00 - file or 0a01 - directory
				$length	= $this->dec(substr($content, $pointer, 2)); $pointer+=2;
				$file	= substr($content, $pointer, $length); $pointer+=$length;
				if($type == '0a01'){ //Directory
					//Make directory
					echo 'Directory'.$file."\r\n";
					mkdir($file, 0755);
				}else if($type == '0a00'){ //File
					echo 'File'.$file."\r\n";
					$size_of_content	= $this->dec(substr($content, $pointer, 4)); $pointer+=4;
					$data				= substr($content, $pointer, $size_of_content); $pointer+=$size_of_content;	
					$number_of_tables	= $this->dec(substr($content, $pointer, 1)); $pointer+=1;
					for($i=0; $i<$number_of_tables; $i++){
						$table_length	= $this->dec(substr($content, $pointer, 2)); $pointer+=2;
						$table			= substr($content, $pointer, $table_length); $pointer+=$table_length;
						//Inflate
						$table_pointer = 0;
						while($table_pointer < strlen($table)){
							$key = substr($table, $table_pointer, 1); $table_pointer+=1;
							$key = unpack("H*", $key);
							$key = $this->hex2bin($key[1]);
							$value = substr($table, $table_pointer, 2); $table_pointer+=2;
							$value = unpack("H*", $value);
							$value = $this->hex2bin($value[1]);
							$data = str_replace($key, $value, $data);
						}
					}
					file_put_contents($file, $data);
				}else{ //Error
				}
			}//End while
		}//End checksum test
	}
}

$c = new BKG();
//$c->compress("C:/Projects/Booger/index.php", "booger.pkg", ".git, *_notes");
//$c->inflate("booger.pkg");
?>