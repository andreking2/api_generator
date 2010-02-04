<?php

class DocMarkdown {

/**
 * The text being parsed.
 *
 * @var string
 */
	protected $_text = null;

/**
 * Contains a hash map of placeholders => content
 *
 * @var array
 */
	var $_placeHolders = array();

/**
 * Parses $text containing doc-markdown text and generates the correct 
 * HTML
 *
 * ### Options:
 *
 * - stripHtml - remove any HTML before parsing.
 *
 * @param string $text Text to be converted
 * @param array $options Array of options for converting
 * @return string Parsed HTML
 */
	public function parse($text, $options = array()) {
		if (!empty($options['stripHtml'])) {
			$text = strip_tags($text);
		}
		$text = str_replace("\r\n", "\n", $text);
		$text = $this->_runBlocks($text);
		return $text;
	}

/**
 * Runs the block syntax elements in the correct order.
 * The following block syntaxes are supported
 *
 * - ATX style headers
 * - Code blocks
 * - lists
 * - paragraph
 *
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _runBlocks($text) {
		$text = $this->_doHeaders($text);
		$text = $this->_doParagraphs($text);
		return $text;
	}

/**
 * Run the header elements
 *
 * @param string $text Text to be transformed
 * @return string Transformed text
 */
	protected function _doHeaders($text) {
		$headingPattern = '/(#+)\s([^#\n]+)(#*)/';
		return preg_replace_callback($headingPattern, array($this, '_headingHelper'), $text);
	}

/**
 * Heading callback method
 *
 * @return string Transformed text
 */
	protected function _headingHelper($matches) {
		$count = strlen($matches[1]);
		if ($count > 6) {
			$count = 6;
		}
		return $this->_makePlaceHolder(sprintf('<h%s>%s</h%s>', $count, trim($matches[2]), $count));
	}

/**
 * Create paragraphs
 *
 * @return void
 */
	protected function _doParagraphs($text) {
		$blocks = preg_split('/\n\n/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		for ($i = 0, $len = count($blocks); $i < $len; $i++) {
			if (substr($blocks[$i], 0, 5) === 'B0x1A') {
				$blocks[$i] = $this->_replacePlaceHolders($blocks[$i]);
			} else {
				$blocks[$i] = '<p>' . $this->_runInline($blocks[$i]) . '</p>';
			}
		}
		return implode("\n\n", $blocks);
	}

/**
 * Run the inline syntax elements against $text
 * The following Inline elements are supported:
 *
 * - em
 * - strong
 * - code
 * - inline link
 * - autolink
 * - entity encoding
 *
 * In addition two special elements are parsed by a helper class specific to the 
 * API generation being used.
 *
 * - Class::method()
 * - Class::$property
 *
 * @param string $text Text to convert.
 * @return string Transformed text.
 */
	protected function _runInline($text) {
		$text = $this->_encodeEntities($text);
		$text = $this->_doItalicAndBold($text);
		$text = $this->_doInlineCode($text);
		$text = $this->_doInlineLink($text);
		$text = $this->_doAutoLink($text);
		$text = $this->_replacePlaceHolders($text);
		return $text;
	}

/**
 * Converts < and & as they are the most dangerous characters to leave behind.
 *
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _encodeEntities($text) {
		return str_replace(array('&', '<'), array('&amp;', '&lt;'), $text);
	}

/**
 * Transform `*italic* and **bold**` into `<em>italic</em> and <strong>bold</strong>`
 *
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _doItalicAndBold($text) {
		$boldPattern = '/(\*\*|__)(?=\S)(.+?[\*_]*)(?<=\S)\1/';
		$italicPattern = '/(\*|_)(?=\S)(.+?)(?<=\S)\1/';
		$text = preg_replace($boldPattern, '<strong>\2</strong>', $text);
		$text = preg_replace($italicPattern, '<em>\2</em>', $text);
		return $text;
	}

/**
 * Transform `text` into <code>text</code>
 *
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _doInlineCode($text) {
		$codePattern = '/(`+)(?=\S)(.+?[`]*)(?=\S)\1/';
		return preg_replace($codePattern, '<code>\2</code>', $text);
	}

/**
 * Convert url into anchor elements.  Converts both
 * http://www.foo.com  and www.foo.com
 * 
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _doAutoLink($text) {
		$wwwPattern = '/((https?:\/\/|www\.)[^\s]+)\s/';
		return preg_replace_callback($wwwPattern, array($this, '_autoLinkHelper'), $text);
	}

/**
 * Helper callback method for autoLink replacement.
 *
 * @return void
 */
	protected function _autoLinkHelper($matches) {
		if ($matches[2] == 'www.') {
			return sprintf('<a href="http://%s">%s</a> ', $matches[1], $matches[1]);
		}
		return sprintf('<a href="%s">%s</a> ', $matches[1], $matches[1]);
	}

/**
 * Replace inline links [foo bar](http://foo.com) with <a href="http://foo.com">foo bar</a>
 *
 * @param string $text Text to transform
 * @return string Transformed text.
 */
	protected function _doInlineLink($text) {
		// 1 = name, 2 = url , 3 = title + quotes, 4 = quote, 5 = title 
		$linkPattern = '/\[([^\]]+)\]\s*\(([^ \t]+)([\s\t]*([\"|\'])(.+)\4)?\)/';
		return preg_replace_callback($linkPattern, array($this, '_inlineLinkHelper'), $text);
	}

/**
 * Helper function for replacing of inline links
 *
 * @return string Text
 * @see DocMarkdown::_doInlineLink()
 */
	protected function _inlineLinkHelper($matches) {
		$title = null;
		if (isset($matches[5])) {
			$title = ' title="' . $matches[5] . '"';
		}
		return $this->_makePlaceHolder(sprintf('<a href="%s"%s>%s</a>', $matches[2], $title, $matches[1]));
	}

/**
 * Replace placeholders in $text with the literal values in the _placeHolders array.
 *
 * @param string $text Text to have placeholders replaced in.
 * @return string Text with placeholders replaced.
 **/
	protected function _replacePlaceHolders($text) {
		foreach ($this->_placeHolders as $marker => $replacement) {
			$replaced = 0;
			$text = str_replace($marker, $replacement, $text, $replaced);
			if ($replaced > 0) {
				unset($this->_placeHolders[$marker]);
			}
		}
		return $text;
	}

/**
 * Convert $text into a placeholder text string
 *
 * @param string $text Text to convert into a placeholder marker
 * @return string
 **/
	protected function _makePlaceHolder($text) {
		$count = count($this->_placeHolders);
		$marker = 'B0x1A' . $count;
		$this->_placeHolders[$marker] = $text;
		return $marker;
	}
}