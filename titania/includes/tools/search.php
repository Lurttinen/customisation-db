<?php
/**
*
* @package Titania
* @version $Id$
* @copyright (c) 2008 phpBB Customisation Database Team
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

// Include library in include path (for Zend)
if (titania::$config->search_backend == 'zend')
{
	set_include_path(get_include_path() . PATH_SEPARATOR . realpath(TITANIA_ROOT . 'includes/library/'));
	titania::_include('library/Zend/Search/Lucene', false, 'Zend_Search_Lucene');
}

// Using the phpBB ezcomponents loader
titania::_include('library/ezcomponents/loader', 'phpbb_ezcomponents_loader');
$loader = new phpbb_ezcomponents_loader();
$loader->load_component('search');
unset($loader);

class titania_search
{
	/**
	* Path to store (for the Zend Search index files)
	*/
	const store_path = 'store/search/';

	/**
	* Holds the indexer
	*/
	private static $index = false;

	/**
	* Forcefully set the indexer to not index anything
	*
	* @var bool
	*/
	public static $do_not_index = false;

	/**
	* Initialize the Search
	*/
	public static function initialize()
	{
		if (self::$index === false)
		{
			// Initialize the ezc/Zend Search class
			if (titania::$config->search_backend == 'zend')
			{
				$handler = new ezcSearchZendLuceneHandler(TITANIA_ROOT . self::store_path);
			}
			else if (titania::$config->search_backend == 'solr')
			{
				$handler = new ezcSearchSolrHandler(titania::$config->search_backend_ip, titania::$config->search_backend_port);
			}
			else
			{
				throw new exception('We need a proper search backend selected');
			}
			$manager = new ezcSearchEmbeddedManager;
			self::$index = new ezcSearchSession($handler, $manager);
		}
	}

	/**
	* Index an item
	*
	* @param mixed $object_type The object_type (what this is set to is not entirely important, but must be the same for all items of that type)
	* @param int $object_id The object_id of an item (there can only be one of each id per object_type)
	* @param array $data Array of data (see titania_article)
	*/
	public static function index($object_type, $object_id, $data)
	{
		self::initialize();

		if (self::$do_not_index)
		{
			return;
		}

		$data['id'] = $object_type . '_' . $object_id;
		$data['type'] = $object_type;

		$article = new titania_article();

		// Set some defaults
		$data = array_merge(array(
			'access_level'	=> TITANIA_ACCESS_PUBLIC,
			'approved'		=> true,
			'reported'		=> false,
		), $data);

		$article->setState($data);

		// Run the update routine instead of the index, this way we should not ever run into issues with duplication
		self::$index->update($article);

		unset($article);
	}

	/**
	* Faster way to index multiple items
	*
	* @param array $data 2 dimensional array containing an array of the data needed to index.  In the array for each item be sure to specify object_type and object_id
	*/
	public static function mass_index($data)
	{
		self::initialize();

		self::$index->beginTransaction();

		foreach ($data as $row)
		{
			$object_type = $row['object_type'];
			$object_id = $row['object_id'];
			unset($row['object_type'], $row['object_id']);

			self::index($object_type, $object_id, $row);
		}

		self::$index->commit();
	}

	/**
	* Delete an item
	*
	* @param mixed $object_type The object_type (what this is set to is not entirely important, but must be the same for all items of that type)
	* @param int $object_id The object_id of an item (there can only be one of each id per object_type)
	*/
	public static function delete($object_type, $object_id)
	{
		self::initialize();

		self::$index->deleteById($object_type . '_' . $object_id, $object_type);
	}

	/**
	* Truncate the entire search or a specific type
	*
	* @param mixed $object_type The object_type you would like to remove, false to truncate the entire search index
	*/
	public static function truncate($object_type = false)
	{
		self::initialize();

		$query = self::$index->createDeleteQuery('titania_article');

		if ($object_type !== false)
		{
			$query->where(
				$query->eq('type', $object_type)
			);
		}

		self::$index->delete($query);
	}

	/**
	* Perform a normal search
	*
	* @param string $search_query The user input for a search query
	* @param object|bool $pagination The pagination class
	* @param array $fields The fields to search
	*
	* @return The documents of the result
	*/
	public static function search($search_query, &$pagination, $fields = array('text', 'title'))
	{
		self::initialize();

		self::clean_keywords($search_query);

		$query = self::$index->createFindQuery('titania_article');
		$qb = new ezcSearchQueryBuilder();
		$qb->parseSearchQuery($query, $search_query, $fields);
		unset($qb);

		return self::custom_search($query, $pagination);
	}

	/**
	* Search by the author
	*
	* @param mixed $user_id
	* @param mixed $pagination
	*/
	public static function author_search($user_id, &$pagination)
	{
		self::initialize();

		$query = self::$index->createFindQuery('titania_article');
		$query->where($query->eq('author', $user_id));

		return self::custom_search($query, $pagination);
	}

	/**
	* Create a find query and return (to create our own custom searches)
	*/
	public static function create_find_query()
	{
		self::initialize();

		return self::$index->createFindQuery('titania_article');
	}

	/**
	* Perform a custom search (must build a createFindQuery for the query)
	*
	* @param object $query self::$index->createFindQuery
	* @param object|bool $pagination The pagination class
	*
	* @return The documents of the result
	*/
	public static function custom_search($query, &$pagination)
	{
		self::initialize();

		$query->offset = $pagination->start;
		$query->limit = $pagination->limit;

		$search_results = self::$index->find($query);

		$pagination->total = $search_results->resultCount;

		$results = array(
			'user_ids'		=> array(),
			'documents'		=> array(),
		);

		foreach ($search_results->documents as $result)
		{
			$results['user_ids'][] = $result->document->author;
			$results['documents'][] = $result->document;
		}

		return $results;
	}

	/**
	* Clean some keywords up
	*
	* @param string $keywords
	*/
	public static function clean_keywords(&$keywords)
	{
		// Replace | with or
		$keywords = str_replace('|', ' or ', $keywords);
	}
}

class titania_article implements ezcBasePersistable, ezcSearchDefinitionProvider
{
	public $id;
	public $title;
	public $text;
	public $text_uid;
	public $text_bitfield;
	public $text_options;
	public $date;
	public $author;
	public $url;
	public $type;
	public $access_level;
	public $approved;
	public $reported;

	public function __construct() {}

	public function getState()
	{
		$state = array(
			'id'			=> $this->id,
			'title'			=> $this->title,
			'text'			=> $this->text,
			'text_uid'		=> $this->text_uid,
			'text_bitfield'	=> $this->text_bitfield,
			'text_options'	=> (int) $this->text_options,
			'author'		=> (int) $this->author,
			'date'			=> (int) $this->date,
			'url'			=> $this->url,
			'type'			=> (int) $this->type,
			'access_level'	=> (int) $this->access_level,
			'approved'		=> ($this->approved) ? 1 : 0,
			'reported'		=> ($this->reported) ? 1 : 0,
		);
		return $state;
	}

	public function setState(array $state)
	{
		foreach ($state as $key => $value)
		{
			$this->$key = $value;
		}
	}

	static public function getDefinition()
	{
		$doc = new ezcSearchDocumentDefinition( __CLASS__ );

		$doc->idProperty = 'id';

		$doc->fields['id']				= new ezcSearchDefinitionDocumentField('id', ezcSearchDocumentDefinition::TEXT);
		$doc->fields['type']			= new ezcSearchDefinitionDocumentField('type', ezcSearchDocumentDefinition::INT);

		$doc->fields['title']			= new ezcSearchDefinitionDocumentField('title', ezcSearchDocumentDefinition::TEXT, 2, true, false, true);
		$doc->fields['text']			= new ezcSearchDefinitionDocumentField('text', ezcSearchDocumentDefinition::TEXT, 1, true, false, true);
		$doc->fields['text_uid']		= new ezcSearchDefinitionDocumentField('text_uid', ezcSearchDocumentDefinition::STRING, 0);
		$doc->fields['text_bitfield']	= new ezcSearchDefinitionDocumentField('text_bitfield', ezcSearchDocumentDefinition::STRING, 0);
		$doc->fields['text_options']	= new ezcSearchDefinitionDocumentField('text_options', ezcSearchDocumentDefinition::INT, 0);

		$doc->fields['author']			= new ezcSearchDefinitionDocumentField('author', ezcSearchDocumentDefinition::INT);
		$doc->fields['date']			= new ezcSearchDefinitionDocumentField('date', ezcSearchDocumentDefinition::INT);
		$doc->fields['url']				= new ezcSearchDefinitionDocumentField('url', ezcSearchDocumentDefinition::STRING, 0);

		$doc->fields['access_level']	= new ezcSearchDefinitionDocumentField('access_level', ezcSearchDocumentDefinition::INT);
		$doc->fields['approved']		= new ezcSearchDefinitionDocumentField('approved', ezcSearchDocumentDefinition::INT);
		$doc->fields['reported']		= new ezcSearchDefinitionDocumentField('reported', ezcSearchDocumentDefinition::INT);

		return $doc;
	}
}