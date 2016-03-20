<?php

/**
* A CSSList is the most generic container available. Its contents include CSSRuleSet as well as other CSSList objects.
* Also, it may contain CSSImport and CSSCharset objects stemming from @-rules.
*/
abstract class CSSList {
	private $aContents;
	
	public function __construct() {
		$this->aContents = array();
	}

  public function prepend($oItem)
  {
    array_unshift($this->aContents, $oItem);
  }
	
	public function append($oItem) {
		$this->aContents[] = $oItem;
	}

  public function extend(Array $aItems) {
    foreach ($aItems as $oItem) {
      $this->aContents[] = $oItem;
    }
  }

  public function removeItemAt($iIndex) {
    unset($this->aContents[$iIndex]);
  }

  public function insertItemsAt(Array $oItems, $iIndex) {
    array_splice($this->aContents, $iIndex, 0, $oItems);
  }
	
	public function __toString() {
		$sResult = '';
		foreach($this->aContents as $oContent) {
			$sResult .= $oContent->__toString();
		}
		return $sResult;
	}
	
	public function getContents() {
		return $this->aContents;
  }
  public function setContents(Array $aContents) {
    $this->aContents = $aContents;
  }
	
	protected function allDeclarationBlocks(&$aResult) {
		foreach($this->aContents as $mContent) {
			if($mContent instanceof CSSDeclarationBlock) {
				$aResult[] = $mContent;
			} else if($mContent instanceof CSSList) {
				$mContent->allDeclarationBlocks($aResult);
			}
		}
	}
	
	protected function allRuleSets(&$aResult) {
		foreach($this->aContents as $mContent) {
			if($mContent instanceof CSSRuleSet) {
				$aResult[] = $mContent;
			} else if($mContent instanceof CSSList) {
				$mContent->allRuleSets($aResult);
			}
		}
	}
	
	protected function allValues($oElement, &$aResult, $sSearchString = null, $bSearchInFunctionArguments = false) {
		if($oElement instanceof CSSList) {
			foreach($oElement->getContents() as $oContent) {
				$this->allValues($oContent, $aResult, $sSearchString, $bSearchInFunctionArguments);
			}
		} else if($oElement instanceof CSSRuleSet) {
			foreach($oElement->getRules($sSearchString) as $oRule) {
				$this->allValues($oRule, $aResult, $sSearchString, $bSearchInFunctionArguments);
			}
		} else if($oElement instanceof CSSRule) {
			$this->allValues($oElement->getValue(), $aResult, $sSearchString, $bSearchInFunctionArguments);
		} else if($oElement instanceof CSSValueList) {
			if($bSearchInFunctionArguments || !($oElement instanceof CSSFunction)) {
				foreach($oElement->getListComponents() as $mComponent) {
					$this->allValues($mComponent, $aResult, $sSearchString, $bSearchInFunctionArguments);
				}
			}
		} else {
			//Non-List CSSValue or String (CSS identifier)
			$aResult[] = $oElement;
		}
	}

	protected function allSelectors(&$aResult, $sSpecificitySearch = null) {
		foreach($this->getAllDeclarationBlocks() as $oBlock) {
			foreach($oBlock->getSelectors() as $oSelector) {
				if($sSpecificitySearch === null) {
					$aResult[] = $oSelector;
				} else {
					$sComparison = "\$bRes = {$oSelector->getSpecificity()} $sSpecificitySearch;";
					eval($sComparison);
					if($bRes) {
						$aResult[] = $oSelector;
					}
				}
			}
		}
	}
}

/**
* The root CSSList of a parsed file. Contains all top-level css contents, mostly declaration blocks, but also any @-rules encountered.
*/
class CSSDocument extends CSSList {
	/**
	* Gets all CSSDeclarationBlock objects recursively.
	*/
	public function getAllDeclarationBlocks() {
		$aResult = array();
		$this->allDeclarationBlocks($aResult);
		return $aResult;
	}

	/**
	* @deprecated use getAllDeclarationBlocks()
	*/
	public function getAllSelectors() {
		return $this->getAllDeclarationBlocks();
	}
	
	/**
	* Returns all CSSRuleSet objects found recursively in the tree.
	*/
	public function getAllRuleSets() {
		$aResult = array();
		$this->allRuleSets($aResult);
		return $aResult;
	}
	
	/**
	* Returns all CSSValue objects found recursively in the tree.
	* @param (object|string) $mElement the CSSList or CSSRuleSet to start the search from (defaults to the whole document). If a string is given, it is used as rule name filter (@see{CSSRuleSet->getRules()}).
	* @param (bool) $bSearchInFunctionArguments whether to also return CSSValue objects used as CSSFunction arguments.
	*/
	public function getAllValues($mElement = null, $bSearchInFunctionArguments = false) {
		$sSearchString = null;
		if($mElement === null) {
			$mElement = $this;
		} else if(is_string($mElement)) {
			$sSearchString = $mElement;
			$mElement = $this;
		}
		$aResult = array();
		$this->allValues($mElement, $aResult, $sSearchString, $bSearchInFunctionArguments);
		return $aResult;
	}

	/**
	* Returns all CSSSelector objects found recursively in the tree.
	* Note that this does not yield the full CSSDeclarationBlock that the selector belongs to (and, currently, there is no way to get to that).
	* @param $sSpecificitySearch An optional filter by specificity. May contain a comparison operator and a number or just a number (defaults to "==").
	* @example getSelectorsBySpecificity('>= 100')
	*/
	public function getSelectorsBySpecificity($sSpecificitySearch = null) {
		if(is_numeric($sSpecificitySearch) || is_numeric($sSpecificitySearch[0])) {
			$sSpecificitySearch = "== $sSpecificitySearch";
		}
		$aResult = array();
		$this->allSelectors($aResult, $sSpecificitySearch);
		return $aResult;
	}
  
  /**
   * Expands all shorthand properties to their long value
   */ 
  public function expandShorthands()
  {
    foreach($this->getAllDeclarationBlocks() as $oDeclaration)
    {
      $oDeclaration->expandShorthands();
    }
  }

  /*
   * Create shorthands properties whenever possible
   */
  public function createShorthands()
  {
    foreach($this->getAllDeclarationBlocks() as $oDeclaration)
    {
      $oDeclaration->createShorthands();
    }
  }

  /**
   * Merge multiple CSS RuleSets by cascading according to the CSS 2.1 cascading rules 
   * (http://www.w3.org/TR/REC-CSS2/cascade.html#cascading-order).
   * 
	 * @param array $aDeclarations An array of CSSDeclarationBlock objects.
	 *
   * @return CSSDeclarationBlock.
   * 
   * ==== Cascading
   * If a CSSDeclarationBlock object has its +specificity+ defined, that specificity is 
   * used in the cascade calculations.  
   * 
   * If no specificity is explicitly set and the CSSDeclarationBlock has *one* selector, 
   * the specificity is calculated using that selector.
   * 
   * If no selectors or multiple selectors are present, the specificity is 
   * treated as 0.
   * 
   * ==== Example #1
	 * <code>
   *   $oDecl_1 = new CSSDeclarationBlock();
   *   $oRule_1 = new CSSRule('color');
   *   $oRule_1->addValue(new CSSColor('#F00'));
   *   $oDecl_1->addRule($oRule_1);
   *   $oDecl_2 = new CSSDeclarationBlock();
   *   $oRule_2 = new CSSRule('margin');
   *   $oRule_2->addValue(
   *     new CSSSize(0, 'px')
   *   );
   *   $oDecl_2->addRule($oRule_2);
   * 
   *   $oMerged = CSSDocument::mergeDeclarations($oDecl_1, $oDecl_2);
   * 
   *   echo $oMerged;
   *   //=> "{ margin: 0px; color: rgb(255,0,0); }"
   * </code>
	 * ==== Example #2
	 * <code>
   *   $oDecl_1 = new CSSDeclarationBlock();
   *   $oRule_1 = new CSSRule('background-color');
   *   $oRule_1->addValue(new CSSColor('black'));
   *   $oDecl_1->addRule($oRule_1);
   *   $oDecl_2 = new CSSDeclarationBlock();
   *   $oRule_2 = new CSSRule('background-image');
   *   $oRule_2->addValue('none');
   *   $oDecl_2->addRule($oRule_2);
   * 
   *   $oMerged = CSSDocument::mergeDeclarations($oDecl_1, $oDecl_2);
   * 
   *   echo $oMerged;
	 *   //=> "{ background: none rgb(0,0,0); }"
	 * </code>
   **/
  static function mergeDeclarations(Array $aDeclarations)
  {
    // Internal storage of CSS properties that we will keep
    $aProperties = array();
    foreach($aDeclarations as $oDeclaration)
    {
      $oDeclaration->expandShorthands();
      $aSelectors = $oDeclaration->getSelectors();
      $iSpecificity = 0;
      if(count($aSelectors) == 1)
      {
        $iSpecificity = $aSelectors[0]->getSpecificity();
      }
      foreach($oDeclaration->getRules() as $sProperty => $oRule)
      {
        // Add the property to the list to be folded per
        // http://www.w3.org/TR/CSS21/cascade.html#cascading-order 
        if(!isset($aProperties[$sProperty]))
        {
          $aProperties[$sProperty] = array(
            'values' => $oRule->getValues(),
            'specificity' => $iSpecificity,
            'important' => $oRule->getIsImportant()
          );
        }
        else
        {
          $aProperty = $aProperties[$sProperty];
          if($aProperty['specificity'] <= $iSpecificity && !$aProperty['important'])
          {
            $aProperties[$sProperty] = array(
              'values' => $oRule->getValues(),
              'specificity' => $iSpecificity,
              'important' => $oRule->getIsImportant()
            );
          }
        }
        if($oRule->getIsImportant())
        {
          $aProperties[$sProperty] = array(
            'values' => $oRule->getValues(),
            'specificity' => $iSpecificity,
            'important' => $oRule->getIsImportant()
          );
        }
      } // end foreach rules
    } // end foreach rulesets
    $oMerged = new CSSDeclarationBlock();
    foreach($aProperties as $sProperty => $aDetails)
    {
      $oNewRule = new CSSRule($sProperty);
      foreach($aDetails['values'] as $aValue) {
        $oNewRule->addvalue($aValue); 
      }
      if($aDetails['important'])
      {
        $oNewRule->setIsImportant(true);
      }
      $oMerged->addRule($oNewRule);
    }
    $oMerged->createShorthands();
    return $oMerged;
  }
}

/**
* A CSSList consisting of the CSSList and CSSList objects found in a @media query.
*/
class CSSMediaQuery extends CSSList {
	private $sQuery;
	
	public function __construct() {
		parent::__construct();
		$this->sQuery = null;
	}
	
	public function setQuery($sQuery) {
			$this->sQuery = $sQuery;
	}

	public function getQuery() {
			return $this->sQuery;
	}
	
	public function __toString() {
		$sResult = "@media {$this->sQuery} {";
		$sResult .= parent::__toString();
		$sResult .= '}';
		return $sResult;
	}
}
