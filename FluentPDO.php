<?php


/** 
 * BaseQuery.php
 */


/** Base query builder
 */
abstract class BaseQuery implements IteratorAggregate {

	/** @var FluentPDO */
	private $fpdo;

	/** @var array of definition clauses */
	protected $clauses = array();

	/** @var PDOStatement */
	private $result;

	/** @var float */
	private $time;

	/** @var bool */
	private $object = false;

	protected $statements = array(), $parameters = array();

	protected function __construct(FluentPDO $fpdo, $clauses) {
		$this->fpdo = $fpdo;
		$this->clauses = $clauses;
		$this->initClauses();
	}

	private function initClauses() {
		foreach ($this->clauses as $clause => $value) {
			if ($value) {
				$this->statements[$clause] = array();
				$this->parameters[$clause] = array();
			} else {
				$this->statements[$clause] = null;
				$this->parameters[$clause] = null;
			}
		}
	}

	/**
	 * Add statement for all kind of clauses
	 * @param $clause
	 * @param $statement
	 * @param array $parameters
	 * @return $this|\SelectQuery
	 */
	protected function addStatement($clause, $statement, $parameters = array()) {
		if ($statement === null) {
			return $this->resetClause($clause);
		}
		# $statement !== null
		if ($this->clauses[$clause]) {
			if (is_array($statement)) {
				$this->statements[$clause] = array_merge($this->statements[$clause], $statement);
			} else {
				$this->statements[$clause][] = $statement;
			}
			$this->parameters[$clause] = array_merge($this->parameters[$clause], $parameters);
		} else {
			$this->statements[$clause] = $statement;
			$this->parameters[$clause] = $parameters;
		}
		return $this;
	}

	/**
	 * Remove all prev defined statements
	 * @param $clause
	 * @return $this
	 */
	protected function resetClause($clause) {
		$this->statements[$clause] = null;
		$this->parameters[$clause] = array();
		if (isset($this->clauses[$clause]) && $this->clauses[$clause]) {
			$this->statements[$clause] = array();
		}
		return $this;
	}

	/** Implements method from IteratorAggregate
	 * @return \PDOStatement
	 */
	public function getIterator() {
		return $this->execute();
	}

	/** Execute query with earlier added parameters
	 * @return \PDOStatement
	 */
	public function execute() {
		$query = $this->buildQuery();
		$parameters = $this->buildParameters();

		$result = $this->fpdo->getPdo()->prepare($query);

		// At this point, $result is a PDOStatement instance, or false.
		// PDO::prepare() does not reliably return errors. Some database drivers
		// do not support prepared statements, and PHP emulates them.  Postgres
		// does support prepared statements, but PHP does not call Postgres's
		// prepare function until we call PDOStatement::execute() (below).
		// If PDO::prepare() worked properly, this is where we would check
		// for prepare errors, such as invalid SQL.

		if ($this->object !== false) {
			if (class_exists($this->object)) {
				$result->setFetchMode(PDO::FETCH_CLASS, $this->object);
			} else {
				$result->setFetchMode(PDO::FETCH_OBJ);
			}
		} elseif ($this->fpdo->getPdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) == PDO::FETCH_BOTH) {
			$result->setFetchMode(PDO::FETCH_ASSOC);
		}

		$time = microtime(true);
		if ($result && $result->execute($parameters)) {
			$this->time = microtime(true) - $time;
		} else {
			$result = false;
		}

		$this->result = $result;
		$this->debugger();

		return $result;
	}

	private function debugger() {
		if ($this->fpdo->debug) {
			if (!is_callable($this->fpdo->debug)) {
				$backtrace = '';
				$query = $this->getQuery();
				$parameters = $this->getParameters();
				$debug = '';
				if ($parameters) {
					$debug = "# parameters: " . implode(", ", array_map(array($this, 'quote'), $parameters)) . "\n";
				}
				$debug .= $query;
				$pattern = '(^' . preg_quote(dirname(__FILE__)) . '(\\.php$|[/\\\\]))'; // can be static
				foreach (debug_backtrace() as $backtrace) {
					if (isset($backtrace["file"]) && !preg_match($pattern, $backtrace["file"])) {
						// stop on first file outside FluentPDO source codes
						break;
					}
				}
				$time = sprintf('%0.3f', $this->time * 1000) . ' ms';
				$rows = ($this->result) ? $this->result->rowCount() : 0;
				fwrite(STDERR, "# $backtrace[file]:$backtrace[line] ($time; rows = $rows)\n$debug\n\n");
			} else {
				call_user_func($this->fpdo->debug, $this);
			}
		}
	}

	/**
	 * @return \PDO
	 */
	protected function getPDO() {
		return $this->fpdo->getPdo();
	}

	/**
	 * @return \FluentStructure
	 */
	protected function getStructure() {
		return $this->fpdo->getStructure();
	}

	/** Get PDOStatement result
	 * @return \PDOStatement
	 */
	public function getResult() {
		return $this->result;
	}

	/** Get time of execution
	 * @return float
	 */
	public function getTime() {
		return $this->time;
	}

	/** Get query parameters
	 * @return array
	 */
	public function getParameters() {
		return $this->buildParameters();
	}

	/** Get query string
	 * @param boolean $formated  return formated query
	 * @return string
	 */
	public function getQuery($formated = true) {
		$query = $this->buildQuery();
		if ($formated) $query = FluentUtils::formatQuery($query);
		return $query;
	}

	/**
	 * Generate query
	 * @return string
	 * @throws Exception
	 */
	protected function buildQuery() {
		$query = '';
		foreach ($this->clauses as $clause => $separator) {
			if ($this->clauseNotEmpty($clause)) {
				if (is_string($separator)) {
					$query .= " $clause " . implode($separator, $this->statements[$clause]);
				} elseif ($separator === null) {
					$query .= " $clause " . $this->statements[$clause];
				} elseif (is_callable($separator)) {
					$query .= call_user_func($separator);
				} else {
					throw new Exception("Clause '$clause' is incorrectly set to '$separator'.");
				}
			}
		}
		return trim($query);
	}

	private function clauseNotEmpty($clause) {
		if ($this->clauses[$clause]) {
			return (boolean) count($this->statements[$clause]);
		} else {
			return (boolean) $this->statements[$clause];
		}
	}

	private function buildParameters() {
		$parameters = array();
		foreach ($this->parameters as $clauses) {
			if (is_array($clauses)) {
				foreach ($clauses as $value) {
					if (is_array($value) && is_string(key($value)) && substr(key($value), 0, 1) == ':') {
						// this is named params e.g. (':name' => 'Mark')
						$parameters = array_merge($parameters, $value);
					} else {
						$parameters[] = $value;
					}
				}
			} else {
				if ($clauses) $parameters[] = $clauses;
			}
		}
		return $parameters;
	}

	protected function quote($value) {
		if (!isset($value)) {
			return "NULL";
		}
		if (is_array($value)) { // (a, b) IN ((1, 2), (3, 4))
			return "(" . implode(", ", array_map(array($this, 'quote'), $value)) . ")";
		}
		$value = $this->formatValue($value);
		if (is_float($value)) {
			return sprintf("%F", $value); // otherwise depends on setlocale()
		}
		if ($value === false) {
			return "0";
		}
		if (is_int($value) || $value instanceof FluentLiteral) { // number or SQL code - for example "NOW()"
			return (string) $value;
		}
		return $this->fpdo->getPdo()->quote($value);
	}

	private function formatValue($val) {
		if ($val instanceof DateTime) {
			return $val->format("Y-m-d H:i:s"); //! may be driver specific
		}
		return $val;
	}

	/**
	 * Select an item as object
	 * @param  boolean|object $object If set to true, items are returned as stdClass, otherwise a class
	 *                                name can be passed and a new instance of this class is return.
	 *                                Can be set to false to return items as an associative array.
	 * @return \BaseQuery
	 */
	public function asObject($object = true) {
		$this->object = $object;
		return $this;
	}

}



/** 
 * CommonQuery.php
 */


/** CommonQuery add JOIN and WHERE clauses for (SELECT, UPDATE, DELETE)
 */
abstract class CommonQuery extends BaseQuery {

	/** @var array of used tables (also include table from clause FROM) */
	protected $joins = array();

	/** @var boolean disable adding undefined joins to query? */
	protected $isSmartJoinEnabled = true;

	public function enableSmartJoin() {
		$this->isSmartJoinEnabled = true;
		return $this;
	}

	public function disableSmartJoin() {
		$this->isSmartJoinEnabled = false;
		return $this;
	}

	public function isSmartJoinEnabled() {
		return $this->isSmartJoinEnabled;
	}

	/** Add where condition, more calls appends with AND
	 * @param string $condition  possibly containing ? or :name (PDO syntax)
	 * @param mixed $parameters  array or a scalar value
	 * @return \SelectQuery
	 */
	public function where($condition, $parameters = array()) {
		if ($condition === null) {
			return $this->resetClause('WHERE');
		}
		if (!$condition) {
			return $this;
		}
		if (is_array($condition)) { // where(array("column1" => 1, "column2 > ?" => 2))
			foreach ($condition as $key => $val) {
				$this->where($key, $val);
			}
			return $this;
		}
		$args = func_get_args();
		if (count($args) == 1) {
			return $this->addStatement('WHERE', $condition);
		}
		if (count($args) == 2 && preg_match('~^(NOT )?`?[a-z_:][a-z0-9_.:]*`?$~i', $condition)) {
			# condition is column only
			if (is_null($parameters)) {
				return $this->addStatement('WHERE', "$condition is NULL");
			} elseif (is_array($args[1])) {
				$in = $this->quote($args[1]);
				return $this->addStatement('WHERE', "$condition IN $in");
			}
			$condition = "$condition = ?";
		}
		array_shift($args);
		return $this->addStatement('WHERE', $condition, $args);
	}

	/**
	 * @param $clause
	 * @param array $parameters - first is $statement followed by $parameters
	 * @return $this|\SelectQuery
	 */
	public function __call($clause, $parameters = array()) {
		$clause = FluentUtils::toUpperWords($clause);
		if ($clause == 'GROUP') $clause = 'GROUP BY';
		if ($clause == 'ORDER') $clause = 'ORDER BY';
		if ($clause == 'FOOT NOTE') $clause = "\n--";
		$statement = array_shift($parameters);
		if (strpos($clause, 'JOIN') !== FALSE) {
			return $this->addJoinStatements($clause, $statement, $parameters);
		}
		return $this->addStatement($clause, $statement, $parameters);
	}

	protected function getClauseJoin() {
		return implode(' ', $this->statements['JOIN']);
	}

	/**
	 * Statement can contain more tables (e.g. "table1.table2:table3:")
	 * @param $clause
	 * @param $statement
	 * @param array $parameters
	 * @return $this|\SelectQuery
	 */
	private function addJoinStatements($clause, $statement, $parameters = array()) {
		if ($statement === null) {
			$this->joins = array();
			return $this->resetClause('JOIN');
		}
		if (array_search(substr($statement, 0, -1), $this->joins) !== FALSE) {
			return $this;
		}

		# match "tables AS alias"
		preg_match('~`?([a-z_][a-z0-9_\.:]*)`?(\s+AS)?(\s+`?([a-z_][a-z0-9_]*)`?)?~i', $statement, $matches);
		$joinAlias = '';
		$joinTable = '';
		if ($matches) {
			$joinTable = $matches[1];
			if (isset($matches[4]) && !in_array(strtoupper($matches[4]), array('ON', 'USING'))) {
				$joinAlias = $matches[4];
			}
		}

		if (strpos(strtoupper($statement), ' ON ') || strpos(strtoupper($statement), ' USING')) {
			if (!$joinAlias) $joinAlias = $joinTable;
			if (in_array($joinAlias, $this->joins)) {
				return $this;
			} else {
				$this->joins[] = $joinAlias;
				$statement = " $clause $statement";
				return $this->addStatement('JOIN', $statement, $parameters);
			}
		}

		# $joinTable is list of tables for join e.g.: table1.table2:table3....
		if (!in_array(substr($joinTable, -1), array('.', ':'))) {
			$joinTable .= '.';
		}

		preg_match_all('~([a-z_][a-z0-9_]*[\.:]?)~i', $joinTable, $matches);
		if (isset($this->statements['FROM'])) {
			$mainTable = $this->statements['FROM'];
		} elseif (isset($this->statements['UPDATE'])) {
			$mainTable = $this->statements['UPDATE'];
		}
		$lastItem = array_pop($matches[1]);
		array_push($matches[1], $lastItem);
		foreach ($matches[1] as $joinItem) {
			if ($mainTable == substr($joinItem, 0, -1)) continue;

			# use $joinAlias only for $lastItem
			$alias = '';
			if ($joinItem == $lastItem) $alias = $joinAlias;

			$newJoin = $this->createJoinStatement($clause, $mainTable, $joinItem, $alias);
			if ($newJoin) $this->addStatement('JOIN', $newJoin, $parameters);
			$mainTable = $joinItem;
		}
		return $this;
	}

	/**
	 * Create join string
	 * @param $clause
	 * @param $mainTable
	 * @param $joinTable
	 * @param string $joinAlias
	 * @return string
	 */
	private function createJoinStatement($clause, $mainTable, $joinTable, $joinAlias = '') {
		if (in_array(substr($mainTable, -1), array(':', '.'))) {
			$mainTable = substr($mainTable, 0, -1);
		}
		$referenceDirection = substr($joinTable, -1);
		$joinTable = substr($joinTable, 0, -1);
		$asJoinAlias = '';
		if ($joinAlias) {
			$asJoinAlias = " AS $joinAlias";
		} else {
			$joinAlias = $joinTable;
		}
		if (in_array($joinAlias, $this->joins)) {
			# if join exists don't create same again
			return '';
		} else {
			$this->joins[] = $joinAlias;
		}
		if ($referenceDirection == ':') {
			# back reference
			$primaryKey = $this->getStructure()->getPrimaryKey($mainTable);
			$foreignKey = $this->getStructure()->getForeignKey($mainTable);
			return " $clause $joinTable$asJoinAlias ON $joinAlias.$foreignKey = $mainTable.$primaryKey";
		} else {
			$primaryKey = $this->getStructure()->getPrimaryKey($joinTable);
			$foreignKey = $this->getStructure()->getForeignKey($joinTable);
			return " $clause $joinTable$asJoinAlias ON $joinAlias.$primaryKey = $mainTable.$foreignKey";
		}
	}

	/**
	 * @return string
	 */
	protected function buildQuery() {
		# first create extra join from statements with columns with referenced tables
		$statementsWithReferences = array('WHERE', 'SELECT', 'GROUP BY', 'ORDER BY');
		foreach ($statementsWithReferences as $clause) {
			if (array_key_exists($clause, $this->statements)) {
				$this->statements[$clause] = array_map(array($this, 'createUndefinedJoins'), $this->statements[$clause]);
			}
		}

		return parent::buildQuery();
	}

	/** Create undefined joins from statement with column with referenced tables
	 * @param string $statement
	 * @return string  rewrited $statement (e.g. tab1.tab2:col => tab2.col)
	 */
	private function createUndefinedJoins($statement) {
		if (!$this->isSmartJoinEnabled) {
			return $statement;
		}

		preg_match_all('~\\b([a-z_][a-z0-9_.:]*[.:])[a-z_]*~i', $statement, $matches);
		foreach ($matches[1] as $join) {
			if (!in_array(substr($join, 0, -1), $this->joins)) {
				$this->addJoinStatements('LEFT JOIN', $join);
			}
		}

		# don't rewrite table from other databases
		foreach ($this->joins as $join) {
			if (strpos($join, '.') !== FALSE && strpos($statement, $join) === 0) {
				return $statement;
			}
		}

		# remove extra referenced tables (rewrite tab1.tab2:col => tab2.col)
		$statement = preg_replace('~(?:\\b[a-z_][a-z0-9_.:]*[.:])?([a-z_][a-z0-9_]*)[.:]([a-z_*])~i', '\\1.\\2', $statement);
		return $statement;
	}
}



/** 
 * DeleteQuery.php
 */


/** DELETE query builder
 *
 * @method DeleteQuery  leftJoin(string $statement) add LEFT JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method DeleteQuery  innerJoin(string $statement) add INNER JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method DeleteQuery  from(string $table) add LIMIT to query
 * @method DeleteQuery  orderBy(string $column) add ORDER BY to query
 * @method DeleteQuery  limit(int $limit) add LIMIT to query
 */
class DeleteQuery extends CommonQuery {

	private $ignore = false;

	public function __construct(FluentPDO $fpdo, $table) {
		$clauses = array(
			'DELETE FROM' => array($this, 'getClauseDeleteFrom'),
			'DELETE' => array($this, 'getClauseDelete'),
			'FROM' => null,
			'JOIN' => array($this, 'getClauseJoin'),
			'WHERE' => ' AND ',
			'ORDER BY' => ', ',
			'LIMIT' => null,
		);

		parent::__construct($fpdo, $clauses);

		$this->statements['DELETE FROM'] = $table;
		$this->statements['DELETE'] = $table;
	}

	/** DELETE IGNORE - Delete operation fails silently
	 * @return \DeleteQuery
	 */
	public function ignore() {
		$this->ignore = true;
		return $this;
	}

	/**
	 * @return string
	 */
	protected function buildQuery() {
		if ($this->statements['FROM']) {
			unset($this->clauses['DELETE FROM']);
		} else {
			unset($this->clauses['DELETE']);
		}
		return parent::buildQuery();
	}

	/** Execute DELETE query
	 * @return boolean
	 */
	public function execute() {
		$result = parent::execute();
		if ($result) {
			return $result->rowCount();
		}
		return false;
	}

	protected function getClauseDelete() {
		return 'DELETE' . ($this->ignore ? " IGNORE" : '') . ' ' . $this->statements['DELETE'];
	}

	protected function getClauseDeleteFrom() {
		return 'DELETE' . ($this->ignore ? " IGNORE" : '') . ' FROM ' . $this->statements['DELETE FROM'];
	}
}



/** 
 * FluentLiteral.php
 */


/** SQL literal value
 */
class FluentLiteral {

	protected $value = '';

	/** Create literal value
	 * @param string $value
	 */
	function __construct($value) {
		$this->value = $value;
	}

	/** Get literal value
	 * @return string
	 */
	function __toString() {
		return $this->value;
	}
}



/** 
 * FluentPDO.php
 */

/**
 * FluentPDO is simple and smart SQL query builder for PDO
 *
 * For more information @see readme.md
 *
 * @link http://github.com/lichtner/fluentpdo
 * @author Marek Lichtner, marek@licht.sk
 * @copyright 2012 Marek Lichtner
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 */


class FluentPDO {

	private $pdo, $structure;

	/** @var boolean|callback */
	public $debug;

	function __construct($dsn, $username = '', $password = '', FluentStructure $structure = null) {
		$this->pdo = new PDO($dsn, $username, $password);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
		if (!$structure) {
			$structure = new FluentStructure;
		}
		$this->structure = $structure;
	}

	/** Create SELECT query from $table
	 * @param string $table  db table name
	 * @param integer $primaryKey  return one row by primary key
	 * @return \SelectQuery
	 */
	public function from($table, $primaryKey = null) {
		$query = new SelectQuery($this, $table);
		if ($primaryKey) {
			$tableTable = $query->getFromTable();
			$tableAlias = $query->getFromAlias();
			$primaryKeyName = $this->structure->getPrimaryKey($tableTable);
			$query = $query->where("$tableAlias.$primaryKeyName", $primaryKey);
		}
		return $query;
	}

	/** Create INSERT INTO query
	 *
	 * @param string $table
	 * @param array $values  you can add one or multi rows array @see docs
	 * @return \InsertQuery
	 */
	public function insertInto($table, $values = array()) {
		$query = new InsertQuery($this, $table, $values);
		return $query;
	}

	/** Create UPDATE query
	 *
	 * @param string $table
	 * @param array|string $set
	 * @param string $primaryKey
	 *
	 * @return \UpdateQuery
	 */
	public function update($table, $set = array(), $primaryKey = null) {
		$query = new UpdateQuery($this, $table);
		$query->set($set);
		if ($primaryKey) {
			$primaryKeyName = $this->getStructure()->getPrimaryKey($table);
			$query = $query->where($primaryKeyName, $primaryKey);
		}
		return $query;
	}

	/** Create DELETE query
	 *
	 * @param string $table
	 * @param string $primaryKey  delete only row by primary key
	 * @return \DeleteQuery
	 */
	public function delete($table, $primaryKey = null) {
		$query = new DeleteQuery($this, $table);
		if ($primaryKey) {
			$primaryKeyName = $this->getStructure()->getPrimaryKey($table);
			$query = $query->where($primaryKeyName, $primaryKey);
		}
		return $query;
	}

	/** Create DELETE FROM query
	 *
	 * @param string $table
	 * @param string $primaryKey
	 * @return \DeleteQuery
	 */
	public function deleteFrom($table, $primaryKey = null) {
		$args = func_get_args();
		return call_user_func_array(array($this, 'delete'), $args);
	}

	/** @return \PDO
	 */
	public function getPdo() {
		return $this->pdo;
	}

	/** @return \FluentStructure
	 */
	public function getStructure() {
		return $this->structure;
	}
}



/** 
 * FluentStructure.php
 */


class FluentStructure {

	private $primaryKey, $foreignKey;

	function __construct($primaryKey = 'id', $foreignKey = '%s_id') {
		if ($foreignKey === null) {
			$foreignKey = $primaryKey;
		}
		$this->primaryKey = $primaryKey;
		$this->foreignKey = $foreignKey;
	}

	public function getPrimaryKey($table) {
		return $this->key($this->primaryKey, $table);
	}

	public function getForeignKey($table) {
		return $this->key($this->foreignKey, $table);
	}

	private function key($key, $table) {
		if (is_callable($key)) {
			return $key($table);
		}
		return sprintf($key, $table);
	}
}



/** 
 * FluentUtils.php
 */


class FluentUtils {

	/** Convert "camelCaseWord" to "CAMEL CASE WORD"
	 * @param string $string
	 * @return string
	 */
	public static function toUpperWords($string) {
		return trim(strtoupper(preg_replace('#(.)([A-Z]+)#', '$1 $2', $string)));
	}

	public static function formatQuery($query) {
		$query = preg_replace(
			'/WHERE|FROM|GROUP BY|HAVING|ORDER BY|LIMIT|OFFSET|UNION|ON DUPLICATE KEY UPDATE|VALUES/',
			"\n$0", $query
		);
		$query = preg_replace(
			'/INNER|LEFT|RIGHT|CASE|WHEN|END|ELSE|AND/',
			"\n    $0", $query
		);
		# remove trailing spaces
		$query = preg_replace("/\s+\n/", "\n", $query);
		return $query;
	}
}



/** 
 * InsertQuery.php
 */


/** INSERT query builder
 */
class InsertQuery extends BaseQuery {

	private $columns = array();

	private $firstValue = array();

	private $ignore = false;
	private $delayed = false;

	public function __construct(FluentPDO $fpdo, $table, $values) {
		$clauses = array(
			'INSERT INTO' => array($this, 'getClauseInsertInto'),
			'VALUES' => array($this, 'getClauseValues'),
			'ON DUPLICATE KEY UPDATE' => array($this, 'getClauseOnDuplicateKeyUpdate'),
		);
		parent::__construct($fpdo, $clauses);

		$this->statements['INSERT INTO'] = $table;
		$this->values($values);
	}

	/** Execute insert query
	 * @return integer last inserted id or false
	 */
	public function execute($sequence = null) {
		$result = parent::execute();
		if ($result) {
			return $this->getPDO()->lastInsertId($sequence);
		}
		return false;
	}

	/** Add ON DUPLICATE KEY UPDATE
	 * @param array $values
	 * @return \InsertQuery
	 */
	public function onDuplicateKeyUpdate($values) {
		$this->statements['ON DUPLICATE KEY UPDATE'] = array_merge(
			$this->statements['ON DUPLICATE KEY UPDATE'], $values
		);
		return $this;
	}

	/**
	 * Add VALUES
	 * @param $values
	 * @return \InsertQuery
	 * @throws Exception
	 */
	public function values($values) {
		if (!is_array($values)) {
			throw new Exception('Param VALUES for INSERT query must be array');
		}
		$first = current($values);
		if (is_string(key($values))) {
			# is one row array
			$this->addOneValue($values);
		} elseif (is_array($first) && is_string(key($first))) {
			# this is multi values
			foreach ($values as $oneValue) {
				$this->addOneValue($oneValue);
			}
		}
		return $this;
	}

	/** INSERT IGNORE - insert operation fails silently
	 * @return \InsertQuery
	 */
	public function ignore() {
		$this->ignore = true;
		return $this;
	}
	
	/** INSERT DELAYED - insert operation delay support
	 * @return \InsertQuery
	 */
	public function delayed() {
		$this->delayed = true;
		return $this;
	}

	protected function getClauseInsertInto() {
		return 'INSERT' . ($this->ignore ? " IGNORE" : '') . ($this->delayed ? " DELAYED" : '') . ' INTO ' . $this->statements['INSERT INTO'];
	}

	protected function getClauseValues() {
		$valuesArray = array();
		foreach ($this->statements['VALUES'] as $rows) {
			$quoted = array_map(array($this, 'quote'), $rows);
			$valuesArray[] = '(' . implode(', ', $quoted) . ')';
		}
		$columns = implode(', ', $this->columns);
		$values = implode(', ', $valuesArray);
		return " ($columns) VALUES $values";
	}

	protected function getClauseOnDuplicateKeyUpdate() {
		$result = array();
		foreach ($this->statements['ON DUPLICATE KEY UPDATE'] as $key => $value) {
			$result[] = "$key = " . $this->quote($value);
		}
		return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $result);
	}


	private function addOneValue($oneValue) {
		# check if all $keys are strings
		foreach ($oneValue as $key => $value) {
			if (!is_string($key)) {
				throw new Exception('INSERT query: All keys of value array have to be strings.');
			}
		}
		if (!$this->firstValue) {
			$this->firstValue = $oneValue;
		}
		if (!$this->columns) {
			$this->columns = array_keys($oneValue);
		}
		if ($this->columns != array_keys($oneValue)) {
			throw new Exception('INSERT query: All VALUES have to same keys (columns).');
		}
		$this->statements['VALUES'][] = $oneValue;
	}

}



/** 
 * SelectQuery.php
 */


/**
 * SELECT query builder
 *
 * @method SelectQuery  select(string $column) add one or more columns in SELECT to query
 * @method SelectQuery  leftJoin(string $statement) add LEFT JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method SelectQuery  innerJoin(string $statement) add INNER JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method SelectQuery  groupBy(string $column) add GROUP BY to query
 * @method SelectQuery  having(string $column) add HAVING query
 * @method SelectQuery  orderBy(string $column) add ORDER BY to query
 * @method SelectQuery  limit(int $limit) add LIMIT to query
 * @method SelectQuery  offset(int $offset) add OFFSET to query
 */
class SelectQuery extends CommonQuery implements Countable {

	private $fromTable, $fromAlias;

	function __construct(FluentPDO $fpdo, $from) {
		$clauses = array(
			'SELECT' => ', ',
			'FROM' => null,
			'JOIN' => array($this, 'getClauseJoin'),
			'WHERE' => ' AND ',
			'GROUP BY' => ',',
			'HAVING' => ' AND ',
			'ORDER BY' => ', ',
			'LIMIT' => null,
			'OFFSET' => null,
			"\n--" => "\n--",
		);
		parent::__construct($fpdo, $clauses);

		# initialize statements
		$fromParts = explode(' ', $from);
		$this->fromTable = reset($fromParts);
		$this->fromAlias = end($fromParts);

		$this->statements['FROM'] = $from;
		$this->statements['SELECT'][] = $this->fromAlias . '.*';
		$this->joins[] = $this->fromAlias;
	}

	/** Return table name from FROM clause
	 * @internal
	 */
	public function getFromTable() {
		return $this->fromTable;
	}

	/** Return table alias from FROM clause
	 * @internal
	 */
	public function getFromAlias() {
		return $this->fromAlias;
	}

	/** Returns a single column
	 * @param int $columnNumber
	 * @return string
	 */
	public function fetchColumn($columnNumber = 0) {
		if ($s = $this->execute()) {
			return $s->fetchColumn($columnNumber);
		}
		return false;
	}

	/** Fetch first row or column
	 * @param string $column column name or empty string for the whole row
	 * @return mixed string, array or false if there is no row
	 */
	public function fetch($column = '') {
		$return = $this->execute();
		if ($return === false) {
			return false;
		}
		$return = $return->fetch();
		if ($return && $column != '') {
			if (is_object($return)) {
				return $return->{$column};
			} else {
				return $return[$column];
			}
		}
		return $return;
	}

	/**
	 * Fetch pairs
	 * @param $key
	 * @param $value
	 * @param $object
	 * @return array of fetched rows as pairs
	 */
	public function fetchPairs($key, $value, $object = false) {
		if ($s = $this->select(null)->select("$key, $value")->asObject($object)->execute()) {
			return $s->fetchAll(PDO::FETCH_KEY_PAIR);
		}
		return false;
	}

	/** Fetch all row
	 * @param string $index  specify index column
	 * @param string $selectOnly  select columns which could be fetched
	 * @return array of fetched rows
	 */
	public function fetchAll($index = '', $selectOnly = '') {
		if ($selectOnly) {
			$this->select(null)->select($index . ', ' . $selectOnly);
		}
		if ($index) {
			$data = array();
			foreach ($this as $row) {
				if (is_object($row)) {
					$data[$row->{$index}] = $row;
				} else {
					$data[$row[$index]] = $row;
				}
			}
			return $data;
		} else {
			if ($s = $this->execute()) {
				return $s->fetchAll();
			}
			return false;
		}
	}

	/** Countable interface
	 * doesn't break current fluentpdo select query
	 * @return
	 */
	public function count() {
		$fpdo = clone $this;
		return (int) $fpdo->select(null)->select('COUNT(*)')->fetchColumn();
	}
}



/** 
 * UpdateQuery.php
 */


/** UPDATE query builder
 *
 * @method UpdateQuery  leftJoin(string $statement) add LEFT JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method UpdateQuery  innerJoin(string $statement) add INNER JOIN to query
 *                        ($statement can be 'table' name only or 'table:' means back reference)
 * @method UpdateQuery  orderBy(string $column) add ORDER BY to query
 * @method UpdateQuery  limit(int $limit) add LIMIT to query
 */
class UpdateQuery extends CommonQuery {

	public function __construct(FluentPDO $fpdo, $table) {
		$clauses = array(
			'UPDATE' => array($this, 'getClauseUpdate'),
			'JOIN' => array($this, 'getClauseJoin'),
			'SET' => array($this, 'getClauseSet'),
			'WHERE' => ' AND ',
			'ORDER BY' => ', ',
			'LIMIT' => null,
		);
		parent::__construct($fpdo, $clauses);

		$this->statements['UPDATE'] = $table;

		$tableParts = explode(' ', $table);
		$this->joins[] = end($tableParts);
	}

	/**
	 * @param string|array $fieldOrArray
	 * @param null $value
	 * @return $this
	 * @throws Exception
	 */
	public function set($fieldOrArray, $value = false) {
		if (!$fieldOrArray) {
			return $this;
		}
		if (is_string($fieldOrArray) && $value !== false) {
			$this->statements['SET'][$fieldOrArray] = $value;
		} else {
			if (!is_array($fieldOrArray)) {
				throw new Exception('You must pass a value, or provide the SET list as an associative array. column => value');
			} else {
				foreach ($fieldOrArray as $field => $value) {
					$this->statements['SET'][$field] = $value;
				}
			}
		}

		return $this;
	}

	/** Execute update query
	 * @param boolean $getResultAsPdoStatement true to return the pdo statement instead of row count
	 * @return int|boolean|\PDOStatement
	 */
	public function execute($getResultAsPdoStatement = false) {
		$result = parent::execute();
		if ($getResultAsPdoStatement) {
			return $result;
		}
		if ($result) {
			return $result->rowCount();
		}
		return false;
	}

	protected function getClauseUpdate() {
		return 'UPDATE ' . $this->statements['UPDATE'];
	}

	protected function getClauseSet() {
		$setArray = array();
		foreach ($this->statements['SET'] as $field => $value) {
			if ($value instanceof FluentLiteral) {
				$setArray[] = $field . ' = ' . $value;
			} else {
				$setArray[] = $field . ' = ?';
				$this->parameters['SET'][$field] = $value;
			}
		}

		return ' SET ' . implode(', ', $setArray);
	}
}

