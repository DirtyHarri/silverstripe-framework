<?php

namespace SilverStripe\Framework\Test\Behaviour;

use Behat\Behat\Context\ClosuredContextInterface,
	Behat\Behat\Context\TranslatedContextInterface,
	Behat\Behat\Context\BehatContext,
	Behat\Behat\Context\Step,
	Behat\Behat\Exception\PendingException,
	Behat\Mink\Exception\ElementHtmlException,
	Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

use Symfony\Component\DomCrawler\Crawler;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * CmsFormsContext
 *
 * Context used to define steps related to forms inside CMS.
 */
class CmsFormsContext extends BehatContext {
	protected $context;

	/**
	 * Initializes context.
	 * Every scenario gets it's own context object.
	 *
	 * @param   array   $parameters     context parameters (set them up through behat.yml)
	 */
	public function __construct(array $parameters) {
		// Initialize your context here
		$this->context = $parameters;
	}

	/**
	 * Get Mink session from MinkContext
	 */
	public function getSession($name = null) {
		return $this->getMainContext()->getSession($name);
	}

	/**
	 * @Then /^I should see an edit page form$/
	 */
	public function stepIShouldSeeAnEditPageForm() {
		$page = $this->getSession()->getPage();

		$form = $page->find('css', '#Form_EditForm');
		assertNotNull($form, 'I should see an edit page form');
	}

	/**
	 * @When /^I fill in the "(?P<field>([^"]*))" HTML field with "(?P<value>([^"]*))"$/
	 * @When /^I fill in "(?P<value>([^"]*))" for the "(?P<field>([^"]*))" HTML field$/
	 */
	public function stepIFillInTheHtmlFieldWith($field, $value) {
		$page = $this->getSession()->getPage();
		$inputField = $page->findField($field);
		assertNotNull($inputField, sprintf('HTML field "%s" not found', $field));

		$this->getSession()->evaluateScript(sprintf(
			"jQuery('#%s').entwine('ss').getEditor().setContent('%s')",
			$inputField->getAttribute('id'),
			addcslashes($value, "'")
		));
	}

	/**
	 * @When /^I append "(?P<value>([^"]*))" to the "(?P<field>([^"]*))" HTML field$/
	 */
	public function stepIAppendTotheHtmlField($field, $value) {
		$page = $this->getSession()->getPage();
		$inputField = $page->findField($field);
		assertNotNull($inputField, sprintf('HTML field "%s" not found', $field));

		$this->getSession()->evaluateScript(sprintf(
			"jQuery('#%s').entwine('ss').getEditor().insertContent('%s')",
			$inputField->getAttribute('id'),
			addcslashes($value, "'")
		));
	}

	/**
	 * @Then /^the "(?P<locator>([^"]*))" HTML field should contain "(?P<html>.*)"$/
	 */
	public function theHtmlFieldShouldContain($locator, $html) {
		$page = $this->getSession()->getPage();
		$element = $page->findField($locator);
		assertNotNull($element, sprintf('HTML field "%s" not found', $locator));

		$actual = $element->getValue();
		$regex = '/'.preg_quote($html, '/').'/ui';
		if (!preg_match($regex, $actual)) {
			$message = sprintf(
				'The string "%s" was not found in the HTML of the element matching %s "%s". Actual content: "%s"', 
				$html, 
				'named', 
				$locator,
				$actual
			);
			throw new ElementHtmlException($message, $this->getSession(), $element);
		}
	}

	/**
	 * Checks formatting in the HTML field, by analyzing the HTML node surrounding
	 * the text for certain properties.
	 * 
	 * Example: Given "my text" in the "Content" HTML field should be right aligned
	 * Example: Given "my text" in the "Content" HTML field should not be bold
	 *
	 * @todo Use an actual DOM parser for more accurate assertions
	 * 
	 * @Given /^"(?P<text>([^"]*))" in the "(?P<field>([^"]*))" HTML field should(?P<negate>(?: not)?) be (?P<formatting>(.*))$/
	 */
	public function stepContentInHtmlFieldShouldHaveFormatting($text, $field, $negate, $formatting) {
		$page = $this->getSession()->getPage();
		$inputField = $page->findField($field);
		assertNotNull($inputField, sprintf('HTML field "%s" not found', $field));

		$crawler = new Crawler($inputField->getValue());
		$matchedNode = null;
		foreach($crawler->filterXPath('//*') as $node) {
			if(
				$node->firstChild
				&& $node->firstChild->nodeType == XML_TEXT_NODE
				&& stripos($node->firstChild->nodeValue, $text) !== FALSE
			) {
				$matchedNode = $node;
			}
		}
		assertNotNull($matchedNode);

		$assertFn = $negate ? 'assertNotEquals' : 'assertEquals';
		if($formatting == 'bold') {
			call_user_func($assertFn, 'strong', $matchedNode->nodeName);
		} else if($formatting == 'left aligned') {
			if($matchedNode->getAttribute('style')) {
				call_user_func($assertFn, 'text-align: left;', $matchedNode->getAttribute('style'));	
			}
		} else if($formatting == 'right aligned') {
			call_user_func($assertFn, 'text-align: right;', $matchedNode->getAttribute('style'));	
		}
	}

	/**
	 * Selects the first textual match in the HTML editor. Does not support
	 * selection across DOM node boundaries.
	 * 
	 * @When /^I select "(?P<text>([^"]*))" in the "(?P<field>([^"]*))" HTML field$/
	 */
	public function stepIHighlightTextInHtmlField($text, $field) {
		$page = $this->getSession()->getPage();
		$inputField = $page->findField($field);
		assertNotNull($inputField, sprintf('HTML field "%s" not found', $field));
		$inputFieldId = $inputField->getAttribute('id');
		$text = addcslashes($text, "'");

		$js = <<<JS
// TODO <IE9 support
// TODO Allow text matches across nodes
var editor = jQuery('#$inputFieldId').entwine('ss').getEditor(), 
	doc = editor.getDOM().doc,
	sel = editor.getInstance().selection,
	rng = document.createRange(),
	matched = false;
jQuery(doc).find('body *').each(function() {
	if(!matched && this.firstChild && this.firstChild.nodeValue && this.firstChild.nodeValue.match('$text')) {
		rng.setStart(this.firstChild, this.firstChild.nodeValue.indexOf('$text'));
		rng.setEnd(this.firstChild, this.firstChild.nodeValue.indexOf('$text') + '$text'.length);
		sel.setRng(rng);
		editor.getInstance().nodeChanged();
		matched = true;
	}
});
JS;
		$this->getSession()->evaluateScript($js);
	}	

	/**
	 * @Given /^I should see a "([^"]*)" button$/
	 */
	public function iShouldSeeAButton($text) {
		$page = $this->getSession()->getPage();
		$els = $page->findAll('named', array('link_or_button', "'$text'"));
		$matchedEl = null;
		foreach($els as $el) {
			if($el->isVisible()) $matchedEl = $el;
		}
		assertNotNull($matchedEl, sprintf('%s button not found', $text));
	}

	/**
	 * @Given /^I should not see a "([^"]*)" button$/
	 */
	public function iShouldNotSeeAButton($text) {
		$page = $this->getSession()->getPage();
		$els = $page->findAll('named', array('link_or_button', "'$text'"));
		$matchedEl = null;
		foreach($els as $el) {
			if($el->isVisible()) $matchedEl = $el;
		}
		assertNull($matchedEl, sprintf('%s button found', $text));
	}

}
