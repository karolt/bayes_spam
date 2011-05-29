<?php 

class BayesianSpamFilter
{
	const TYPE_SPAM	= 1;
	const TYPE_HAM	= 2;
	
	/**
	 * prawdopodobienstwo przydzielane do tokena, ktory wczesniej nie wystapil w tekscie
	 * Wartosc zaproponowana w artykule "A plan for Spam"
	 * 
	 * @var double
	 */
	const PROBABILITY_FOR_UNKNOWN_TOKEN = 0.4;
	
	/**
	 *  prawdopodobienstwo 'srodkowe' :)
	 *  
	 * @var double
	 */
	const PROBABILITY_NEUTRAL = 0.5;
	
	/**
	 * nazwa tabeli z licznikami przetworzonych dokumentow
	 * 
	 * @var string
	 */
	const DOCUMENTS_CNT_TABLENAME = 'documents_cnt';
	
	/**
	 * nazwa tabeli w bazie danych przetrzymujacej czestosc tokenow
	 * 
	 * @var string
	 */
	const FREQUENCY_TABLENAME = 'token_frequency';
	
	/**
	 * nazwa tabeli w bazie danych przetrzymujacej prawdopodobienstwa dla tokenow
	 * 
	 * @var string
	 */
	const PROBABILITY_TABLENAME = 'token_probability';
	
	/**
	 * uchwyt do bazy z zapisanymi czestosciami tokenami
	 * 
	 * @var XDB
	 */
	private $_dbh;
	
	/**
	 * tablica czestosci wystapien tokenow
	 * 
	 * @var array
	 */
	private $_tokensFrequency;
	
	/**
	 * tablica prawdopodobienstw stwierdzenia, ze wiadomosc jest spamem na podstawie wystapienia danego tokenu
	 * 
	 * @var array
	 */
	private $_tokensProbability;
	
	/**
	 * liczba wszystkich dokumentow zakwalifikowanych jako spam
	 * 
	 * @var int
	 */
	private $_documentsCntSpam;
	
	/**
	 * liczba wszystkich dokumentow zakwalifikowanych jako 'dobre'
	 * 
	 * @var int
	 */
	private $_documentsCntHam;
	
	
	/**
	 * lista tokenow i typow (spam,ham), ktorym zmodyfikowano czestosc
	 * utrzymywana w celu optymalizacji zapisu do bazy danych
	 * 
	 * @var array
	 */
	private $_modifiedFrequencies;
	
	/**
	 * konstruktor
	 * 
	 * @param XDB $dbh
	 */
	public function __construct($dbh)
	{
		$this->_dbh = $dbh;
		
		$this->_tokensFrequency = array();
		$this->_tokensFrequency[self::TYPE_SPAM]	= array();
		$this->_tokensFrequency[self::TYPE_HAM]		= array();
		
		$this->_tokensProbability = array();
		
		$this->_pullDocumentCountersFromDb();
	}
	
	/**
	 * wyciaga z bazy liczniki przetworzonych tekstow i zapisuje do $this->_documentsCntSpam i $this->_documentsCntHam
	 */
	private function _pullDocumentCountersFromDb()
	{
	
		$sql = "SELECT * FROM ".self::DOCUMENTS_CNT_TABLENAME."";
		$data = $this->_dbh->getResults($sql);
		foreach ($data as $row) 
		{
			if ($row['name'] == 'spam') {
				$this->_documentsCntSpam = max (1, $row['cnt']);		
			}
			elseif ($row['name'] == 'ham') {
				$this->_documentsCntHam = max (1, $row['cnt']);
			}
		}
	}
	
	/**
	 * dodaje nowy tekst do bazy wiedzy filtra
	 * 
	 * @param string $text
	 * @param boolean $isSpam
	 */
	public function learnNewData ($text, $isSpam)
	{
		$this->_modifiedFrequencies = array();
		$isSpam ? $this->_documentsCntSpam++ : $this->_documentsCntHam++;
		
		$tokens = $this->_tokenize($text);
		$this->_addNewtokens($tokens, $isSpam ? self::TYPE_SPAM : self::TYPE_HAM);
		
		$this->_saveDataToDb();
	}
	
	/**
	 * zwraca czestosc wystapien danego tokena w spamie lub w dobrym tekscie
	 * 
	 * @param string $token
	 * @param int $type stala self::TYPE_SPAM lub self::TYPE_HAM
	 * @return int
	 */
	public function getTokenFrequency($token, $type)
	{
//		if (!$type) {
//			throw new Exception("type is empty", $code);
//		}
		//echo "\ngetting freq for $token, $type";
		if (isset($this->_tokensFrequency[$type][$token])) {
			//echo "\nreturn from code ".$this->_tokensFrequency[$type][$token];
			return $this->_tokensFrequency[$type][$token]; 
		}
		
		$this->_pullTokenFrequencyFromDb($token);
		//echo "\nreturn from DB ".$this->_tokensFrequency[$type][$token];
		return $this->_tokensFrequency[$type][$token];
		
	}
	
	/**
	 * pobiera z bazy czestosc wystapien danego tokena w spamie lub w dobrym tekscie
	 * wpisuje je do tablicy $this->_tokensFrequency
	 * 
	 * @param string $token
	 */
	private function _pullTokenFrequencyFromDb($token)
	{
		$sql = "SELECT frequency,type FROM ". self::FREQUENCY_TABLENAME." WHERE token='".$this->_dbh->escape($token)."'";
		
		$data = $this->_dbh->getResults($sql);
		if (!is_array($data)) {
			$this->_tokensFrequency[self::TYPE_SPAM][$token]	= 0;
			$this->_tokensFrequency[self::TYPE_HAM][$token] 	= 0;
			
			return;
		}
		$frequency = array();
		foreach ($data as $row) {
			$frequency[$row['type']] = $row['frequency'];
		}

		$this->_tokensFrequency[self::TYPE_SPAM][$token]	= (isset($frequency[self::TYPE_SPAM])) ? $frequency[self::TYPE_SPAM] : 0; 
		$this->_tokensFrequency[self::TYPE_HAM][$token]		= (isset($frequency[self::TYPE_HAM])) ? $frequency[self::TYPE_HAM] : 0;
	}
	
	/**
	 * modyfikuje wartosc czestosci tokena
	 * i odnotuwuje ten fakt, w celu pozniejszego zapisu do bazy
	 * 
	 * @param string $token
	 * @param int $type
	 * @param int $freq
	 */
	private function _modifyTokenFrequency($token, $type, $freq)
	{
		$this->_tokensFrequency[$type][$token] = $freq;
		
		$this->_modifiedFrequencies[$type][$token] = 1;
	}
	
	/**
	 * zwraca prawdopodobienstw stwierdzenia, ze wiadomosc jest spamem na podstawie wystapienia danego tokenu
	 * 
	 * @param string $token
	 */
	public function getTokenProbability($token)
	{
		if (isset($this->_tokensProbability[$token])) {
			return $this->_tokensProbability[$token]; 
		}
		
		$this->_pullProbabilityFromDb($token);
		return $this->_tokensProbability[$token];
		
	}
	
	/**
	 * pobiera prawdopodobienstwo tokena z bazy i zapisuje je do $this->_tokensProbability 
	 * 
	 * @param string $token
	 */
	private function _pullProbabilityFromDb($token) {
		$sql	= "SELECT probability FROM ".self::PROBABILITY_TABLENAME." WHERE token = '".$this->_dbh->escape($token)."'";
		$prob	= $this->_dbh->getVar($sql);
		
		if ($prob) {
			$this->_tokensProbability[$token] = $prob;
		} else {
			$this->_tokensProbability[$token] = null;
		}
		
	}
	
	/**
	 * rozbija tekst na tokeny i zwraca ich czestotliwosc
	 * 
	 * @param string $text
	 * 
	 * @return array
	 */
	private function _tokenize($text)
	{
		$words = explode(' ', $text);
		
		$tokensFrequency = array();
		foreach ($words as $word) 
		{
			if (!isset($tokensFrequency[$word])) {
				$tokensFrequency[$word] = 1;
			} else {
				$tokensFrequency[$word]++;
			}
		}
		
		return $tokensFrequency;
	}
	
	/**
	 * dodaje nowe tokeny i ich czestotliwosc do listy znanych tokenow
	 * 
	 * @param array $tokens
	 * @param int $type stala self::TYPE_SPAM lub self::TYPE_HAM
	 */
	private function _addNewTokens($tokens, $type)
	{
		//echo "adding new tokens";
		foreach ($tokens as $token => $frequency) 
		{
			$currFrequency = $this->getTokenFrequency($token, $type);
			if ($currFrequency == 0) {
				$this->_tokensFrequency[$type][$token] = $frequency;
				$this->_modifyTokenFrequency($token, $type, $frequency);
			} else {
				$this->_modifyTokenFrequency($token, $type, $currFrequency + $frequency);
			}
			
			$this->_tokensProbability[$token] = $this->_computeProbability($token);
			
		}
	}
	
	/**
	 * wylicza prawdopodobienstwo dla danego tokena znajac czestosc wystepowania w 
	 * dokumentach oznaczonych jako spam i w dokumentach dobrych i znajac licznosc tych zbiorow
	 * 
	 * @param string $token
	 * @return double
	 */
	private function _computeProbability($token)
	{
		//pomnozenie przez dwa zaproponowae w rtykyle `A plan for Spam` w celu zmniejszenia false positives
		//echo "\ncomputing for ".$token;
		//if ($token == 'spam') echo "\ncomputing for ".$token;
		$good	= 2 * $this->getTokenFrequency($token, self::TYPE_HAM);
		$bad	= $this->getTokenFrequency($token, self::TYPE_SPAM);
		//if ($token == 'spam') var_dump($good, $bad);
		//jesli token ma max 5 wystapien, to nie w zasadzie go nie uwzgledniamy w obliczeniach przydzielajac mu neutralne prawdopodobienstwo 
		//wartosc 5 rowniez pochodzi z artykulu "A plan for Spam"
//		if ($good + $bad <= 5) {
//			return self::PROBABILITY_NEUTRAL;
//		}
		//var_dump($this->_documentsCntSpam, $this->_documentsCntHam);
		$fractionBad	= min(1, $bad / $this->_documentsCntSpam);
		if ($this->_documentsCntHam == 0) {
			$fractionGood	= 1;
		} else {
			$fractionGood	= min(1, $good / $this->_documentsCntHam);
		}
		//if ($token == 'spam') var_dump($fractionGood, $fractionBad);
		$prob = min(0.99, $fractionBad / ($fractionBad + $fractionGood));
		$prob = max(0.01, $prob);
		//var_dump($token, $prob);
		return $prob;
	}
	
	/**
	 * zapisuje wszystkie dane do bazy danych
	 */
	private function _saveDataToDb()
	{
		$dbFreqData = $dbProbData = $dbDocsData = array();
		
		$sqlFreq = $sqlProb = '';
		foreach ($this->_modifiedFrequencies as $type => $tokens)
		{
			foreach ($tokens as $token =>  $frequency) 
			{
				$tokenCpy = $token;
				$tokenEscaped = $this->_dbh->escape($tokenCpy);
				if ($frequency > 0) {
					//$dbFreqData[] = array('token' => $token, 'type'=> $type, 'frequency' => $this->_tokensFrequency[$type][$token]);
					$sqlFreq .= "('".$tokenEscaped."',". $type.",".$this->_tokensFrequency[$type][$token]."),";
					//$dbProbData[] = array('token' => $token, 'probability' => $this->_tokensProbability[$token]);
					$sqlProb .= "('".$tokenEscaped."',".$this->_tokensProbability[$token]."),";
				}
			}
		}
		$sql = "REPLACE INTO ".self::FREQUENCY_TABLENAME." (token, type, frequency) VALUES ".substr($sqlFreq, 0, -1);
		$this->_dbh->query($sql);
		//$this->_dbh->replace(self::FREQUENCY_TABLENAME, $dbFreqData);
		$sql = "REPLACE INTO ".self::PROBABILITY_TABLENAME." (token, probability) VALUES ".substr($sqlProb, 0, -1);
		//echo "\n\n".$sql;
		$this->_dbh->query($sql);
		//$this->_dbh->replace(self::PROBABILITY_TABLENAME, $dbProbData);
		
		$dbDocsData[] = array('name' => 'spam', 'cnt' => $this->_documentsCntSpam);
		$dbDocsData[] = array('name' => 'ham',  'cnt' => $this->_documentsCntHam);
		$this->_dbh->replace(self::DOCUMENTS_CNT_TABLENAME, $dbDocsData);
	}
	
}

?>