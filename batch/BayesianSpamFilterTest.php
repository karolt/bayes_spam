<?php 
/**
 * Unit Test dla klasy BayesianSpamFilter
 * @author Karol Traczykowski
 */

define('ROOT_DIR', '..');
require_once ROOT_DIR . "/xplod/XAutoloader.class.php";

class BayesianSpamFilterTest extends PHPUnit_Framework_TestCase
{
	
	private $_dbh;
	
	public function __construct()
	{
		$this->_dbh = new XDB('root', '', 'localhost', 'bayes_spam_test');
	}
	
	public function setUp()
	{
		$this->_dbh->query("TRUNCATE token_frequency");
		$this->_dbh->query("TRUNCATE token_probability");
		$this->_dbh->query("TRUNCATE documents_cnt");
		
		$data[] = array('name' => 'spam', 'cnt' => 0);
		$data[] = array('name' => 'ham', 'cnt' => 0);
		$this->_dbh->insert('documents_cnt', $data);
	}
	
	public function xtestLearningNewData()
	{
		$filter = new BayesianSpamFilter($this->_dbh);
		$freqTmp = $filter->getTokenFrequency("jest", BayesianSpamFilter::TYPE_SPAM);
		
		$filter->learnNewData("To jest moja wiadomosc, ktora moze jest spamem", $isSpam = true);
		
		$this->assertEquals($freqTmp + 2, $filter->getTokenFrequency("jest", BayesianSpamFilter::TYPE_SPAM));
	}
	
	public function testStoringData()
	{
		$filter = new BayesianSpamFilter($this->_dbh);
		$filter->learnNewData("To jest moja wiadomosc, ktora moze jest spamem", $isSpam = true);
		
		//lets suppose we start script once more, so we create new filter object
		$freqTmp = $filter->getTokenFrequency("jest", BayesianSpamFilter::TYPE_SPAM);

		$filter2 = new BayesianSpamFilter($this->_dbh);
		$filter2->learnNewData("A to jest inna wiadomosc, bedaca spamem", $isSpam = true);
		$this->assertEquals($freqTmp +1, $filter2->getTokenFrequency("jest", BayesianSpamFilter::TYPE_SPAM));
	}
	
	public function testProbability()
	{
		$filter = new BayesianSpamFilter($this->_dbh);
		$filter->learnNewData("To jest moja wiadomosc, ktora moze jest spam", $isSpam = true);
		$filter->learnNewData("spam spam", $isSpam = true);
		$filter->learnNewData("spam spam", $isSpam = true);
		$filter->learnNewData("A to jest inna wiadomosc, ktora jest bezpieczna", $isSpam = false);
		$filter->learnNewData("Bezpieczna droga do domu jest bezpieczna bo strzeze jej pan policjant", $isSpam = false);
		$prop = $filter->getTokenProbability("jest");
		
		$this->assertEquals(0.99, $filter->getTokenProbability("spam"));
		$this->assertEquals(0.01, $filter->getTokenProbability("bezpieczna"));
		$this->assertEquals(0.2, $filter->getTokenProbability("ktora"));
	}
	
	//pub
}
?>