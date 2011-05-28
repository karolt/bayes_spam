<?php 

class BayesianSpamFilter
{
	const TYPE_SPAM	= 1;
	const TYPE_HAM	= 2;
	
	/**
	 * nazwa tabeli w bazie danych przetrzymujacej czestosc tokenow
	 */
	const FREQUENCY_TABLENAME = 'token_frequency';
	
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
	
	public function __construct($dbh)
	{
		$this->_dbh = $dbh;
		
		$this->_tokensFrequency = array();
		$this->_tokensFrequency[self::TYPE_SPAM]	= array();
		$this->_tokensFrequency[self::TYPE_HAM]		= array();
	}
	/**
	 * dodaje nowy tekst do bazy wiedzy filtra
	 * 
	 * @param string $text
	 * @param boolean $isSpam
	 */
	public function learnNewData ($text, $isSpam)
	{
		$tokens = $this->_tokenize($text);
		$this->_addNewtokens($tokens, $isSpam);
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
		if (isset($this->_tokensFrequency[$type][$token])) {
			return $this->_tokensFrequency[$type][$token]; 
		}
		
		
		$freq = $this->_getTokenFrequencyFromDb($token, $type);
		if ($freq) {
			$this->_tokensFrequency[$type][$token] = $freq;
			return $freq;
		} else {
			$this->_tokensFrequency[$type][$token] = 0;
			return 0;
		}
		
	}
	
	/**
	 * pobiera z bazy czestosc wystapien danego tokena w spamie lub w dobrym tekscie
	 * 
	 * @param string $token
	 * @param int $type stala self::TYPE_SPAM lub self::TYPE_HAM
	 * @return int
	 */
	private function _getTokenFrequencyFromDb($token, $type)
	{
		$sql = "SELECT frequency FROM ". self::FREQUENCY_TABLENAME." WHERE token='".$this->_dbh->escape($token)."' AND type='".$type."'";
		
		return $this->_dbh->getVar($sql);
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
		$dbData = array();
		
		foreach ($tokens as $token => $frequency) 
		{
			$currentFreq = $this->getTokenFrequency($token, $type);
			if ($currentFreq == 0) {
				$this->_tokensFrequency[$type][$token] = $frequency;
			} else {

				$this->_tokensFrequency[$type][$token] += $frequency;
			}

			$dbData[] = array('token' => $token, 'type'=> $type, 'frequency' => $this->_tokensFrequency[$type][$token]);
		}
		
		$this->_dbh->replace(self::FREQUENCY_TABLENAME, $dbData, $sql);
	}
}

?>