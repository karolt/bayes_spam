<?php
/**
 * Klasa dostepu do bazy danych. Interfejs _prawie_ zgodny z Ez-SQL
 * Przystosowana do korzystania z procedur skladowanych z nast. zastrzezeniami:
 * 1. wywolania proc. skladowanych nie sa dobrze przetestowane
 * 2. proc. skladowane ** NIE POWINNY ** zwracac wielokrotnych result-setów
 *      bo jesli tak sie stanie, to nie bedzie widoczny ** ZADEN ** z nich
 * 
 * @TODO: przerobienie klasy na obsluge PHP_MYSQLi   
 *     
 * @package xplod
 * @subpackage db 
 * @author Jacek Erd <jacek.erd@gmail.com>
 */
class XDB
{
	const MASTER_SERVER = true;
	const MAX_DEADLOCK_ATTEMPTS = 3;
	
    protected $dbh = null;
    protected $dbName = "";
    public $errorReporting = false;
    public $queryLog = "";
    //slowa ktore musza zawierac sqlki pisane do loga
    protected $logFilter = array();
	protected $queryStats = array(
								  "select"		=> 0, 
								  "update"		=> 0, 
								  "insert"		=> "0", 
								  "delete"		=> 0, 
								  "call"		=> 0, 
								  "set"			=> 0, 
								  "lock"		=> 0, 
								  "unlock"		=> 0, 
								  "replace"		=> 0, 
								  "undefined"	=> 0,
								 );
    protected $numQuesries = 0;
    public $rowsAffected;
    public $insertId;
    protected $lastQuery = "";
    protected $lastResult = null;
    protected $maserMode = false;
    protected $lastError = null;
    
    /**
     * Konstruktor. Zapewnia polaczenie z serwerem i wybranie bazy.
     * 
     * @param string $dbUser nazwa uzytkownika MySQL
     * @param string $dbPassword haslo MySQL
     * @param string $dbHost adres serwera
     * @param string $dbName nazwa bazy danych
     */                                 
    public function __construct($dbUser, $dbPassword, $dbHost, $dbName, $queryLog = "", $masterMode = true)
    {
        $this->queryLog = $queryLog;
        $this->dbName = $dbName;
        /*
         // zeby mozna uzywac procedur skladowanych nalezy miec wlaczona flage
         // CLIENT_MULTI_RESULTS. W innym wypadku dostaje sie blad
        // "can't return a result set in the given context"
        // niestety w driverze MySQL-PHP nie mamy zdefiniowanej takiej flagi
            
        // no wiec nalezy siegnac do zrodel, zdefiniowac ja
        if (!defined(MYSQL_CLIENT_MULTI_RESULTS))
            define("MYSQL_CLIENT_MULTI_RESULTS", 131072);
        // ta flaga tez moze byc przydatna, ale na razie nie wiadomo do czego ;)
        if (!defined(MYSQL_CLIENT_MULTI_STATEMENTS))
            define("MYSQL_CLIENT_MULTI_STATEMENTS", 65536);
        */
        
        // no i odpalamy mysql'a z naszymi nowymi flagami
        //$this->dbh = @mysql_connect($dbHost,$dbUser,$dbPassword, false, MYSQL_CLIENT_MULTI_STATEMENTS | MYSQL_CLIENT_MULTI_RESULTS);

        $this->dbh = @mysql_connect($dbHost,$dbUser,$dbPassword, false);
		$this->masterMode = $masterMode;
        if (!$this->dbh )
            $this->printError("<ol><b>Error establishing a database connection!</b><li>Are you sure you have the correct user/password?<li>Are you sure that you have typed the correct hostname?<li>Are you sure that the database server is running?</ol>");
		

		if ($dbName) {
        	$this->selectDB($dbName);
		}
        // a tu są 2 query, dla durnego mysqla 4.x, ktory nie umie sie przelaczyc na utf
        //$this->query("SET CHARACTER SET utf8");
        //$this->query("SET NAMES utf8");
    }
    /**
     * Wybiera podana baze. Wypisuje blad przy niepowodzeniu.
     * 
     * @param string $dbName nazwa bazy danych
     */                   
    private function selectDB($dbName)
    {
        if (!@mysql_select_db($dbName, $this->dbh))
            $this->printError("<ol><b>Error selecting database <u>$dbName</u>!</b><li>Are you sure it exists?<li>Are you sure there is a valid database connection?</ol>");
    }
    /**
     * Wypisuje blad, o ile wypisywanie bledow nie zostalo wylaczone
     * 
     * @param string $msg opcjonalny komunikat o bledzie
     */     
    protected function printError($msg="")
    {
        if(false === $this->dbh)
        	$this->lastError = false;
        else 
            $this->lastError = mysql_errno($this->dbh);
        
    	if ($this->errorReporting)
        {
            $msg = $msg ? $msg : "MySQL ERROR:" . ($this->dbh?mysql_error($this->dbh):'No connection') . " Code: " . $this->lastError;
            echo "<!-- <b>SQL/DB Error:</b> " . $msg . "-->";
        }
    }
    /**
     * Czysci zebrane do tej pory liczniki zapytan
     */     
    public function clearStats()
	{
		$this->query_stats = array("select"		=> 0, 
								   "update"		=> 0, 
								   "insert"		=> "0", 
								   "delete"		=> 0, 
								   "call"		=> 0, 
								   "set"		=> 0, 
								   "lock"		=> 0, 
								   "unlock"		=> 0, 
								   "replace"	=> 0, 
								   "undefined"	=> 0,
								  );
	}
	public function logStats($label="")
	{
		if ($this->queryLog)
		{
			$statStr = "";
			foreach ($this->queryStats as $qryType => $qryCount)
				if ($qryCount)
					$statStr .= $qryType . ": ". $qryCount . " ";
			file_put_contents($this->queryLog, date("Y-m-d H:i:s") . " -- Query statistics" . ($label ? " [$label]" : ""). ": " . ($statStr ? $statStr : "no queries"). "\n", FILE_APPEND);
		}
	}
	private function handleSelect($result, $cleanUp=true)
	{
        $numRows=0;
        if (is_resource($result))
        {
            while ($row = @mysql_fetch_assoc($result))
            {
                // przechowujemy wyniki w polu obiektu
                $this->lastResult[$numRows] = $row;
                $numRows++;
            }
            if ($cleanUp)
                @mysql_free_result($result);
        }
        return $numRows;
    }
    
    /**
     * Info od Jacka Erda
     *
     * Parametr SimpleResult Jeśli false, metoda zwraca liczbę zmodyfikowanych wierszy (rows_affected) więc może się zdarzyć, że zwróci 0 mimo
     * że zapytanie było poprawne, jeśli true, metoda zwraca true jeśli zapytanie zostało wykonane (nie było w nim błędu), false w przeciwnym wypadku
     * 
     * @param string $query Zapytanie Sql
     * @param boolean $directCall Ma być true
     * @param boolean $simpleResult Dla false zwraca affected rows, dla true info czy zapytanie zostało pomyślnie wykonane 
     */
    public function query($query, $directCall = true, $simpleResult = false)
    // parametr directCall blokuje nieskonczana petle wywolan przy forwardowaniu do DBWritera
    {            
        // For reg expressions
        $query = trim($query); 
        list($qryType,) = explode(" ", strtolower($query));
        
        if (isset($this->queryStats[$qryType])) {
        	$this->queryStats[$qryType]++;
        } else {
        	$this->queryStats['undefined']++;
        }
        
        // initialise return
        $returnVal = 0;
        $this->lastResult = null;
        // Keep track of the last query for debug..
        $this->lastQuery = $query;
		// query logging - take timing
		if ($this->queryLog)
			$tStart = microtime(true);
			
        // Perform the query via std mysql_query function..
        $result = $this->_deadlockQuery($query);
		// take timing
        if ($this->queryLog)
	        $queryTimeMs = (microtime(true) - $tStart)*1000;
        $this->numQueries++;

        // If there is an error then take note of it..
        if ($result === FALSE)
        {
        	if ($this->queryLog)
				file_put_contents($this->queryLog, date("Y-m-d H:i:s") . " [ERROR]; " . $query . "; (MySQL ERROR:" . mysql_error($this->dbh) . " Code: " . mysql_errno($this->dbh) .") \n", FILE_APPEND);	
            $this->printError();
            return false;
        }
        
        // dalszy tryb postepowania zalezy od typu zapytania
        // mozliwe sa 3 przypadki: 
        // 1. insert|delete|update|replace
        // 2. select|explain
        // 3. call        
        
        // ad1. insert|delete|update|replace
        if ($qryType == "insert" || $qryType == "update" || $qryType == "delete" || $qryType == "replace")
        {	
        	
        	$this->rowsAffected = mysql_affected_rows($this->dbh);
                
            // przy replace oraz insert ustawiamy insertId
            if ($qryType =="insert" || $qryType == "replace")
                $this->insertId = mysql_insert_id($this->dbh);
            // Zwracamy liczbe ruszonych wierszy
            $returnVal = $this->rowsAffected;
            
            if ($simpleResult)
            	$returnVal = $result;
        }
        // poszedl select
        elseif ($qryType == "select" || $qryType == "explain" || $qryType == "show")
        {        
            // zwracamy liczbe wierszy z resultsetu
            $returnVal = $this->handleSelect($result);
        }
        /*
         * wywolanie procedur skladowanych jest czasowo wycofane
         * okazuje sie , ze driver php_mysql jednak slabo wspiera takie wywolania
         * i wywala blad Lost connection to MySQL server during query Code: 2013
         * albo MySQL server has gone away Code: 2006                           
        elseif ($qryType == "call")
        {
            // wywolanie procedury skladowanej jest specyficzne bo:
            // 1. moze zwracac wiecej niz 1 result-set
            // 2. moze jednoczesnie zwracac resultsety oraz wartosc affected_rows
            
            // ad 1. nie robimy  z tym na razie nic. nasze SP **nie powinny** zwracac wielu RS
            // obslugujemy zapytanie CALL tak jak by bylo SELECT'em 
            
            // zwracamy liczbe wierszy z resultsetu
            $returnVal = $this->handleSelect($result, false);
            
            // ad 2.
            $this->rowsAffected = mysql_affected_rows($this->dbh);
            mysql_free_result($result);
        }
        */
		// query logging
		
		if ($this->queryLog)
		{
        	$write = false;
        	//jesli mamy ustawioony filtr na slowa, to sprawdzamy czy nasza sqlka zawiera ktores z nich
        	if (is_array($this->logFilter) && count($this->logFilter))
        	{
    		    foreach ($this->logFilter as $word)
    		    {
    		        if (stristr($query, $word))
    		            $write = true;
    		    }
    		}
            //jesli nie, mozemy spokojnie pisac loga
    		else
    		    $write = true;
		    
		    if ($write)
			    file_put_contents($this->queryLog, date("Y-m-d H:i:s") . " [" . sprintf("%0.2f", $queryTimeMs) . " ms]; " . $query . "; \n", FILE_APPEND);
		}

        return $returnVal;
    }
    
    protected function _deadlockQuery($query) {
    	$current = 0;
    	while ($current++ < self::MAX_DEADLOCK_ATTEMPTS) {
    		$res = @mysql_query($query, $this->dbh);
    		$err = mysql_errno($this->dbh);
    		if(!$res && ($err == '1205' || $err == '1213'  ) )
    			continue;
    		else
    			break;

    	}
    	return $res;
    }

    public function getVar($query)
    {
        $this->query($query);
        if ($this->lastResult)
        {
            list ($ret,) = array_values($this->lastResult[0]);
            return $ret;
        }
        else
            return null;
        
    }
    
    public function getRow($query)
    {
        $this->query($query);
        return $this->lastResult[0];
    }
    public function getCol($query)
    {
        $this->query($query);
        if (is_array($this->lastResult))
        {
            $ret = false;
            foreach ($this->lastResult as $row)
            {
                list($val,) = array_values($row);
                $ret[] = $val;
            }
            return $ret;
        }
        return false;
    }
    public function getResults($query)
    {
        $this->query($query);
        return $this->lastResult;
    }
    
    /**
     * Zabezpiecza znaki specjalne do użytku w zapytaniu SQL
     *
     * @author nexis
     * @param string $value
     * @return string
     */
    public function escape(&$value)
    {
    	$value = mysql_real_escape_string($value, $this->dbh); // TODO mysql_real_escape_string() na lokalnej bazie serwera aplikacyjnego lub funkcja naśladująca
    	
    	return $value;
    }
    
    public function begin($level = 'SERIALIZABLE')
	{
		mysql_query ('SET TRANSACTION ISOLATION LEVEL '.$level, $this->dbh);
		return mysql_query('START TRANSACTION', $this->dbh) ? true : false;
	}
	public function commit()
	{
	    return mysql_query('COMMIT', $this->dbh) ? true : false;
	}
	public function rollback()
	{
	    return mysql_query('ROLLBACK', $this->dbh) ? true : false;
	} 
    public static function join(&$baseData, &$dbh, $table, $fkField, $fields = '*', $fieldPrefix, $tableAlias, $joinedPK = 'id')
	{
		// jesli nie ma do czego joinowac
		if (!is_array($baseData) || !count($baseData))
			return;
			
		$fk = array();
		
		foreach ($baseData as $d)
		{
			if (isset($d[$fkField])) {
				$fk[] = intval($d[$fkField]);
			}
		}
			
		$fk = array_unique($fk);
		if (count($fk) > 0) {
			if ($fields == '*') {
				$fieldStr = $tableAlias . '.*';
			} else {
				foreach ($fields as $num => $f)
					$fields[$num] = sprintf('`%1$s`.`%2$s` AS `%3$s%2$s`', $tableAlias, $f, $fieldPrefix);
				$fieldStr = sprintf('%s.%s AS %s%s, %s', $tableAlias, $joinedPK, $fieldPrefix, $joinedPK, implode(', ', $fields));
			}
			$qry = sprintf("SELECT %s FROM %s %s WHERE %s IN (%s)", $fieldStr, $table, $tableAlias, $joinedPK, implode(', ', $fk));
			$res = $dbh->getResults($qry);
			// jesli nie ma czego joinowac
			if (!is_array($res)) {
				return;
			}
			
			foreach ($res as $r)
			{
				$joinedData[$r[$fieldPrefix . $joinedPK]] = $r;
			}
			foreach ($baseData as $num => $d)
			{
				if(isset($d[$fkField]) && isset($joinedData[$d[$fkField]])) {
					$baseData[$num] = array_merge($d, $joinedData[$d[$fkField]]);
				}
			}
		}
	}
    
    public function backup($sql)
    {
        $logId = Xplod::getInstance()->getModlogId();
        if ($logId == 0)
            return;
            
        preg_match('/select[\s\n\r\*]+from[\s\n\r]+(([a-z1-9_]+)\.)?([a-z1-9_]+)([\n\r]+(.+))?/i',$sql,$res);
        //2: nazwa bazy, jesli podano
        //3: nazwa tabeli
        //5: cały where, jesli podano
        
        $table = $res[3];
        $sql = "INSERT INTO " . $this->dbName . "_trash." . $table . " SELECT '$logId', trash_tmp.* FROM ($sql) trash_tmp";
        $this->query($sql);
    }
    
    public function setQueryLog($queryLog)
    {
        $this->queryLog = $queryLog;
    }
    public function close()
    {
    	mysql_close ($this->dbh);
    }
    
    public function pingDB() {
    	if(!$this->dbh) return false;
    	return mysql_ping($this->dbh);
    }
    
    public function lastError() {
    	return $this->lastError;
    }

    /**
     * Wstawia wiersz(e) do tabeli
     *
     * @author nexis,karol
     * @param string $table Nazwa tabeli
     * @param array $data Jedno- lub wielowymiarowa tablica danych
     * @param string $sql Referencja do zapytanie SQL
     * @throws Exception
     * @return int Ilość wstawionych wierszy
     */
    public function insert($table, array $data, &$sql = '')
    {
	   	 return $this->_write('INSERT', $table, $data, $sql);
    }
    
    /**
     * Nadpisuje wiersz(e) w tabeli
     *
     * @author karol
     * @param string $table Nazwa tabeli
     * @param array $data Jedno- lub wielowymiarowa tablica danych
     * @param string $sql Referencja do zapytanie SQL
     * @throws Exception
     * @return int Ilość wstawionych wierszy
     */
    public function replace($table, array $data, &$sql = '')
    {
    	return $this->_write('REPLACE', $table, $data, $sql);
    }
    
    /**
     * przeprowadza akcje zapisu - insert lub replace
     *
     * @author nexis, karol
	 * @see insert, replace
     * @param string $type 'INSERT' lub 'REPLACE'
     * @param array $data
     * @param string $sql Referencja do zapytanie SQL
     * @throws Exception
     */
    private function _write($type, $table, array $data, &$sql = '')
    {
    	// Sprawdzenie nazwy tabeli
    	if (empty($table)) {
    		throw new Exception('Empty table name');
    	}
    	
    	// Sprawdzenie danych
    	if (empty($data)) {
    		throw new Exception('No data provided');	
    	}
    	
    	// Przekształcamy jednowymiarową tablicę w wielowymiarową
    	if (!$this->_arrayIsMultidimensional($data)) {
    		$data = array($data);	
    	}
    	
    	$cols	= array();
    	$values	= array();
    	$count	= count($data);
    	foreach ($data as $row) {
    		// Sortujemy klucze, jeśli mamy wielowymiarową tablicę
    		if ($count > 1) {
    			ksort($row);
    		}
    		
    		// Sprawdzamy zgodność kluczy
	    	if (empty($cols)) {
	    		$cols = array_keys($row);
	    	} else {
	    		if ($cols !== array_keys($row)) {
	    			throw new Exception('Array sizes are inconsistent');
	    		}
	    	}
	    	
	    	array_walk($row, array($this, 'escape'));
	    	
	        $values[] = "('" . implode("', '", array_values($row)) . "')";
	        
    	}
    	
    	$operation = null;
    	switch ($type) {
    		case 'INSERT':
    			$operation = 'INSERT';
    			break;
    		case 'REPLACE':
    			$operation = 'REPLACE';
    			break;
    		default:
    			throw new Exception('Unknown operation');
    	}
        //$sql = $operation ." INTO `" . $table . "` (`" . implode('`, `', $cols) . "`) VALUES " . implode(', ', $values);
        $sql = $operation ." INTO " . $table . " (" . implode(', ', $cols) . ") VALUES " . implode(', ', $values);
        
    	return $this->query($sql);
    }
    
    /**
     * Usuwa wiersz(e) z tabeli
     *
     * @author nexis
     * @param string $table Nazwa tabeli
     * @param string $conditions Warunki
     * @param string $sql Referencja do zapytanie SQL
     * @throws Exception
     * @return int Ilość usuniętych wierszy
     */
    public function delete($table, array $conditions, &$sql = '')
    {
    	// Sprawdzenie nazwy tabeli
    	if (empty($table)) {
    		throw new Exception('Empty table name');
    	}
    	
    	$sql = "DELETE FROM `" . $table . "` WHERE " . $this->_buildWhere($conditions);
    	
    	return (int) $this->query($sql);
    }
    
	/**
	 * Aktualizuje wiersz(e) w tabeli
	 *
	 * @author nexis
	 * @param string $table Nazwa tabeli
	 * @param array $data Para kolumn i wartości
	 * @param array $conditions Warunki
	 * @param string $sql Referencja do zapytanie SQL
	 * @throws Exception
	 * @return int Ilość zaktualizowanych wierszy
	 */
    public function update($table, array $data, array $conditions, &$sql = '')
    {
	    // Sprawdzenie nazwy tabeli
    	if (empty($table)) {
    		throw new Exception('Empty table name');
    	}
    	
        $sql = "UPDATE `" . $table . "` SET " . $this->_buildSet($data) . " WHERE " . $this->_buildWhere($conditions);
    	
    	return $this->query($sql);
    }
    
    /**
     * Tworzy warunek do zapytania
     *
     * @author nexis
     * @param array $conditions
     * @throws Exception
     * @return string
     */
    protected function _buildWhere(array $conditions)
    {
	    // Sprawdzenie warunków zapytania
    	if (empty($conditions)) {
    		throw new Exception('No conditions specified');	
    	}
    	
    	$where = array();
    	foreach ($conditions as $col => $val) {
    		if (is_array($val)) {
    			array_walk($val, array($this, 'escape'));
    			$where[] = "`" . $col . "` IN ('" . implode("', '", $val) . "')";
    		} else {
    			$where[] = "`" . $col . "` = '" . $this->escape($val) . "'";
    		}
    	}
    	
    	return implode(' AND ', $where);
    }
    
    /**
     * Tworzy zestaw danych do aktualizacji
     *
     * @author nexis
     * @param array $data
     * @throws Exception
     * @return string
     */
    protected function _buildSet(array $data)
    {
	    // Sprawdzenie danych
    	if (empty($data)) {
    		throw new Exception('No data provided');	
    	}
    	
    	array_walk($data, array($this, 'escape'));
    	
    	$sets = array();
    	foreach ($data as $col => $val) {
    		$sets[] = "`" . $col . "` = '" . $val . "'";
    	}
    	
    	return implode(", ", $sets);
    }
    
    protected function _arrayIsMultidimensional(array $array)
	{
    	$arrays = array_filter($array, 'is_array');
    	
    	if (count($arrays) === 0) {
    		return false;
    	} else {
    		return true;
    	}
	}
}
?>