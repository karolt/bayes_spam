<?php
class XAutoloader
{
	/*
	 * wiem wiem ze to brzydkie jak noc pazdziernikowa..
	 * ale to na szybko i "na chwile" bo ogolnie moznaby przebudowac autoloader 
	 * zeby szybciej dzialal i oferowal wieksza funkcjonalnosc
	 * 
	 * kamil
	 */
	private static $staticActionControllers = array(
		'AdminAdmonishAction' => array(
			'dir'	=> '/actions/admin/',
			
		)
	);
	
    public static function preload($filepath)
    {
        require_once ROOT_DIR . "/" . $filepath;
    }
    private static function extractPath($className)
    {
        if (($x = strrpos($className, '/'))=== false)
            return "";
        return substr($className, 0, $x);
    }
    // zamienia groups/InfoBlock => GroupsInfoBlock
    public static function extractClassName($className)
    {
        $p = explode("/", $className);
        $cn = "";
        foreach ($p as $part)
            $cn .= ucfirst($part);
        return $cn;
    }
    public static function getClassFile($className)
    {
    	// jesli klasa frameworka        
        if (strtolower($className{0})=="x") {
            $reqDir = "/xplod";
        } else {
			$reqDir = "/lib";
        }
        // wymuszamy pierwsza litere duza       
        $className = ucfirst($className);
        return ROOT_DIR . $reqDir . "/" . $className . ".class.php";
    }
    public static function classExists($className)
    {
        $ret = file_exists(self::getClassFile($className));
        return $ret; 
    }
    
    public static function load($className)
    {
    	require_once self::getClassFile($className);  
    }
}

function __autoload($className)
{
	XAutoloader::load($className);
}
//spl_autoload_register(array(XAutoloader,load));


?>