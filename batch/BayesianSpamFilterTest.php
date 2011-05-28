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
		$this->_dbh = new XDB('root', '', 'localhost', 'bayes_spam');
	}
	
	public function testLearningNewData()
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
	
	public function testPropability()
	{
		$filter = new BayesianSpamFilter($this->_dbh);
		$this->assertTrue($filter->getTokenPropability("jest") > 0);
	}
	
	//pub
}
?>