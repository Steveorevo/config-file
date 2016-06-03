<?php
/**
 * The ConfigFile class allows for easy querying and updating of popular
 * configuration and source code file formats (Apache config, PHP, ini, etc).
 * Note: find, get, and set refer to the *configuration file* "key" not to be
 * confused with an array key.
 *
 * The most common used methods are load, save, get_key, and set_key.
 */
namespace Steveorevo\ConfigFile;
use Steveorevo\GString;

class ConfigFile {
	public $comment_chars = '';
	public $prepend_value = '';
	public $append_value = '';
	public $last_isolate = '';
	public $isolate_count = 0;
	public $prepend_key = '';
	public $append_key = '';
	public $last_block = [ ];
	public $last_index = 0;
	public $last_key = '';
	public $before = [ ];
	public $block = [ ];
	public $after = [ ];

	/**
	 * Load the given file if provided.
	 *
	 * @param string $file The file to load for analysis.
	 */
	function __construct( $file = '' ) {
		if ( '' !== $file ) {
			$this->load( $file );
		}
	}

	/**
	 * Load a file for analysis
	 *
	 * @param string $file The path and filename of the file to load.
	 */
	function load( $file ) {
		$s = new GString( $file );

		// Set configuration file type based on extension
		$this->set_type( (string) $s->getRightMost( '.' ) );

		if ( file_exists( $file ) ) {
			$contents = file_get_contents( $file );
		} else {
			$contents = '';
		}

		// Convert Windows CRLF to LF
		$contents = str_replace( "\r\n", "\n", $contents );

		// Load data into isolated block
		$this->block = explode( "\n", $contents );

		// Reset the isolation markers
		$this->last_block  = [ ];
		$this->before      = [ ];
		$this->after       = [ ];
		$this->last_key    = '';
	}

	/**
	 * Set the given boundary markers and options for parsing the file. This
	 * method is automatically called from our load file method.
	 *
	 * @param string $ext The file extension.
	 */
	function set_type( $ext ) {
		switch ( $ext ) {
			case 'ini': // PHP ini file
				$this->comment_chars = '# ';
				$this->prepend_value = ' ';
				$this->append_value  = '';
				$this->prepend_key   = '';
				$this->append_key    = ' =';
				break;
			case 'php-define':
			case 'php': // PHP source code file, where define('key', 'value');
				$this->comment_chars = '// ';
				$this->prepend_value = ' \'';
				$this->append_value  = '\');';
				$this->prepend_key   = 'define(\'';
				$this->append_key    = '\',';
				break;
			case 'php-unquoted': // PHP source code file, where define('key', unquoted value);
				$this->comment_chars = '// ';
				$this->prepend_value = ' ';
				$this->append_value  = ');';
				$this->prepend_key   = 'define(\'';
				$this->append_key    = '\',';
				break;
			case 'conf': // Apache configuration file
				$this->comment_chars = '#';
				$this->prepend_value = '';
				$this->append_value  = '';
				$this->prepend_key   = '';
				$this->append_key    = ' ';
				break;
			case 'cnf': // MySQL my.cnf
				$this->comment_chars = '# ';
				$this->prepend_value = ' ';
				$this->append_value  = '';
				$this->prepend_key   = '';
				$this->append_key    = ' =';
				break;
			case 'php-variable':
				$this->comment_chars = '// ';
				$this->prepend_value = ' \'';
				$this->append_value  = '\';';
				$this->prepend_key   = '$';
				$this->append_key    = ' =';
				break;
			default:
				$this->comment_chars = '// ';
				$this->prepend_value = '\'';
				$this->append_value  = '\';';
				$this->prepend_key   = '';
				$this->append_key    = '=';
				break;
		}
	}

	/**
	 * Merge the before, block, and after into block and reset isolation markers.
	 */
	function merge_block() {
		$merged      = array_merge( $this->before, $this->block, $this->after );
		$this->block = $merged;

		// Reset the isolation markers
		$this->last_block  = [ ];
		$this->before      = [ ];
		$this->after       = [ ];
		$this->last_key    = '';
	}

	/**
	 * Returns the current isolated block of data.
	 *
	 * @return string The block's content.
	 */
	public function get_block() {
		$contents = implode( "\n", $this->block );

		return $contents;
	}

	/**
	 * Overwrites the current block of data with the given content.
	 *
	 * @param string $contents The string data to replace the block with.
	 */
	function set_block( $contents ) {

		// Convert Windows CRLF to LF
		$contents = str_replace( "\r\n", "\n", $contents );

		// Load data into isolated block
		$this->block = explode( "\n", $contents );
	}

	/**
	 * Search and replace text in the currently isolated block.
	 *
	 * @param string $search The string to search for in the given block.
	 * @param string $replace The new string content.
	 */
	function replace_within_block( $search, $replace ) {
		$contents = $this->get_block();
		$contents = str_replace( $search, $replace, $contents );
		$this->set_block( $contents );
	}

	/**
	 * Narrow the focus of our methods to the given unique block within the configuration file.
	 * Our methods (find, get, set, add, remove, etc) will only act within the given valid
	 * region. If the region does not exist or the arguments are empty, then our methods will
	 * apply to the entire file. Invoking the method with the same parameter finds the next
	 * instance of a block with matching begin and end markers. Passing no arguments resets the
	 * isolation to reference the entire configuration file.
	 *
	 * @param string $begin A unique string that markes the beginning of the region.
	 * @param string $end A unique string that identifies the end of the region.
	 *
	 * @return boolean Returns true if an existing isolated block could be found.
	 */
	function isolate_block( $begin = '', $end = '' ) {
		$this->merge_block();
		if ( $this->last_isolate !== $begin . '|' . $end ) {
			$this->isolate_count = 0;
		}else{
			$this->isolate_count++;
		}
		$this->last_isolate = $begin . '|' . $end;
		$begin_indexes = $this->array_find_values( $begin, $this->block );
		$end_indexes = $this->array_find_values( $end, $this->block );

		// Disqualify missing or incomplete blocks
		if ( count( $begin_indexes ) === 0 || count( $end_indexes) === 0
		     || $this->isolate_count > (count( $begin_indexes ) -1)
			 || $this->last_isolate === '|') {
			$this->last_isolate = '';
			return false;
		}
		$begin = $begin_indexes[$this->isolate_count];
		$end = 0;

		// Find first end index that's greater than begin
		while(count($end_indexes) > 0) {
			$end = array_shift($end_indexes);
			if ($end > $begin) {
				break;
			}
		}
		if ($end === 0) return false;

		// Gather before
		for( $n = 0; $n <= $begin; $n++ ) {
			array_push( $this->before, $this->block[$n] );
		}

		// Gather isolated block
		$block = [];
		for( $n = $begin + 1; $n < $end; $n++ ) {
			array_push( $block, $this->block[$n] );
		}

		// Gather after
		for( $n = $end; $n <= (count($this->block) - 1); $n++ ) {
			array_push( $this->after, $this->block[$n] );
		}

		$this->block = $block;
		return true;
	}

	/**
	 * Creates a new block with the given begin and end markers at the end of the
	 * configuration file.
	 *
	 * @param string $begin The beginning marker of the given data block.
	 * @param string $end The ending marker of the given data block.
	 */
	function create_block( $begin, $end ) {
		$this->merge_block();
		$this->before = $this->block;
		array_push( $this->before, $begin);
		$this->block = [];
		array_push( $this->after, $end);
	}

	/**
	 * Removes the current isolated block from a prior isolate_block call. Does nothing
	 * if no block is currently isolated.
	 *
	 */
	function remove_block() {
		if ( $this->last_isolate === '' ) {
			return;
		}
		array_pop($this->before);
		$this->block = [ ];
		array_shift($this->before);
	}

	/**
	 * Finds the given "key" within the configuration file and within the narrowed
	 * scope of an isolated block of code if the "isolate_block" method was used
	 * prior. Invoking the method with the same parameter finds the next instance
	 * of the "key" (merge_block, save, load, isolate_block resets find).
	 *
	 * @param string $key The key to find within the configuration file.
	 *
	 * @return boolean Returns true if the key could be found or false if missing.
	 */
	function find( $key ) {
		$block = $this->block;
		if ( false !== $this->last_index && $this->last_key === $key ) {
			$block = $this->last_block;
		}
		$this->last_key = $key;
		$this->last_index = $this->array_scan( $this->prepend_key . $key . $this->append_key, $block );
		if ( false !== $this->last_index ) {

			// Invalidate the line for subsequent searches, to get the next find
			$this->last_block = $block;
			$this->last_block[$this->last_index] = '';
			return true;
		}
		return false;
	}

	/**
	 * Searches an array for the presence of the given config file "key"; array_search is too literal
	 * and wouldn't be able to decipher a partial match so we do it here with iterative scan and counting
	 * for commented out lines.
	 *
	 * @param string $key The key to search for in the array values.
	 * @param string $block The given array to perform the search on.
	 *
	 * @return bool|int Returns index into array of match or false if none found.
	 */
	function array_scan( $key, $block ) {
		$key = preg_replace( '/\s+/', '', $key );
		$comment = preg_replace( '/\s+/', '', $this->comment_chars . $key );
		$found = false;
		$index = 0;
		foreach( $block as $line ) {
			$line = new GString( preg_replace( '/\s+/', '', $line ) );
			if ( $line->startsWith( $key ) || $line->startsWith( $comment ) ) {
				$found = true;
				break;
			}
			$index++;
		}
		if ($found) {
			return $index;
		}
		return $found;
	}

	/**
	 * Searches an array for all occurrences of the given value and returns an array containing
	 * their index positions or an empty array if the value cannot be found.
	 *
	 * @param string $value The value to search for all occurrences of.
	 * @param string $block The array to be searched.
	 *
	 * @return array
	 */
	function array_find_values( $value, $block ) {
		$next = array_search( $value, $block );
		$positions = [];
		while( false !== $next ) {
			array_push( $positions, $next );
			$block = array_slice( $block, $next + 1);
			$next = array_search( $value, $block );
			if ( false !== $next ) {
				$next = $next + array_sum($positions) + count($positions);
			}
		}
		return $positions;
	}

	/**
	 * Comment out the line containing the key that was found from a prior find
	 * method call.
	 */
	function comment() {
		if ( false === $this->last_index ) {
			return;
		}
		if ( false === $this->is_commented() ) {
			$this->block[ $this->last_index ] = $this->comment_chars . $this->block[ $this->last_index ];
		}
	}

	/**
	 * Checks if the line containing the key that was found from a prior find
	 * method call is commented or uncommented.
	 *
	 * @return boolean Returns true if the given line is commented out.
	 */
	function is_commented() {
		if ( false === $this->last_index ) {
			return false;
		}
		$line = new GString( $this->block[ $this->last_index ] );
		$comment = new GString( $this->comment_chars );
		return $line->trim()->startsWith( $comment->trim()->__toString() ) !== 0;
	}

	/**
	 * Uncomment the line containing the key that was found from a prior find
	 * method call.
	 */
	function uncomment() {
		if ( false === $this->last_index ) {
			return;
		}
		if ( $this->is_commented() ) {
			$line = new GString( $this->block[ $this->last_index ] );
			$comment = $this->comment_chars;
			if ( false === strpos($this->block[ $this->last_index ], $comment) ) {
				$comment = new GString( $comment ); // accomodate missing whitespace
				$comment = $comment->trim()->__toString();
			}
			$this->block[ $this->last_index ] = $line->delLeftMost( $comment )->__toString();
		}
	}

	/**
	 * Gets the current value of a key that was found with a prior method call
	 * to find. Returns null if no valid key was originally found.
	 *
	 * @return string The value of the key found from prior find method call.
	 */
	function get() {
		if ( false === $this->last_index ) {
			return null;
		}
		$line = new GString( $this->block[ $this->last_index ] );
		$line = $line->delLeftMost($this->prepend_key . $this->last_key . $this->append_key);
		return $line->delLeftMost($this->prepend_value)->delRightMost($this->append_value)->__toString();
	}

	/**
	 * The get_key method provides a single method to find and return the existing
	 * value for a single given matching key. The default is returned if no key
	 * has been found.
	 *
	 * @param string $key The key to search for.
	 * @param string $default The default value to return if no key is found.
	 *
	 * @return string The value from the given key.
	 */
	function get_key( $key, $default = '' ) {
		if ( $this->find( $key ) ) {
			return $this->get();
		}else{
			return $default;
		}
	}

	/**
	 * Sets or updates the value for the given key that was found from a prior
	 * find method call. If none was found, it is added to the end of the given
	 * isolated block. Ignored if no prior find was ever invoked.
	 *
	 * @param string $value The new value to
	 */
	function set( $value ) {
		if ( false === $this->last_index ) {
			array_push( $this->block, $this->prepend_key . $this->last_key . $this->append_key .
			                          $this->prepend_value . $value . $this->append_value );
		}else{
			$this->block[$this->last_index] = $this->prepend_key . $this->last_key . $this->append_key .
			                                  $this->prepend_value . $value . $this->append_value;
		}
	}

	/**
	 * The set_key method provides one convenient call to set a value for a key in
	 * a given configuration file. The key is automatically added if it did not
	 * exist prior.
	 *
	 * @param string $key The key to update or add.
	 * @param string $value The value for the given key to set.
	 */
	function set_key( $key, $value ) {
		$this->find( $key );
		$this->set( $value );
	}

	/**
	 * Removes the line containing the key that was found from a prior find
	 * method call. Ignored if the last find returned false.
	 *
	 */
	function remove() {
		if ( false === $this->last_index ) return;
		unset( $this->block[$this->last_index] );
	}

	/**
	 * Saves the current configuration file data from memory to the given file.
	 *
	 * @param string $file The file path and name to save the configuration data to.
	 */
	function save( $file ) {
		$this->merge_block();
		$data = implode( "\n", $this->block );
		file_put_contents( $file, $data );
	}
}
