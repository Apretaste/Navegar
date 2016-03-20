<?php
require_once __DIR__.'/../CSSParser.php';
/**
 * 
 */
class CSSDocumentTest extends PHPUnit_Framework_TestCase
{
  /**
   * 
   **/
  public function testMergeDeclarations()
  {
    $sFile = __DIR__.'/files/merge.css';
    $oParser = new CSSParser();
    $oDoc = $oParser->parseString(file_get_contents($sFile));
    $aDeclarations = $oDoc->getAllDeclarationBlocks();
    $oMerged = CSSDocument::mergeDeclarations($aDeclarations);
    $sExpected = "{color: rgb(255,0,0);background: rgb(0,255,255) none repeat-x 0% 0% scroll;margin: 0 0 1em;}";
    $this->assertEquals(trim($oMerged->__toString()), $sExpected);
  }
}
