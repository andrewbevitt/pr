<?php
/****************************************************************************
PHP Resume (PR): A one-script wonder for simple HTML resumes.
Author: Andrew Bevitt <me@andrewbevitt.com>
Project page: http://andrewbevitt.com/code/pr/
Source code: https://github.com/andrewbevitt/pr/

Markdown parsing is derived from the markdown_limited gist:
  https://gist.github.com/Xeoncross/2244152


--- WHAT'S REQUIRED ---
1. PHP 5 with the JSON PECL Extension
2. .htaccess or equivalent server configuration
3. Some knowledge Markdown


--- GETTING STARTED ---
1. Change the following values for your needs:
*/
define( 'EMAIL_FROM', 'youremail@domain.com' );
define( 'DATA_STORE', 'filename-for-data-store.prdb' );
define( 'SALTING_KEY', 'some-random-string-for-salting-your-password' );
define( 'PRINCE_EXEC', '/usr/bin/prince' ); # If you are using PrinceXML or
define( 'WKHTML_EXEC', '/usr/local/bin/wkhtmltopdf' ); # using WKHTMLTOPDF
/*

2. Upload this file to a folder on your website
   If you're going to use the auto-created .htaccess make sure the
   folder you upload to is empty. If you don't you won't be able 
   to access any other files in that folder.

3. "Install" the script
   Point your browser at: http://domain.com/path/to/index.php?q=install
   This creates the following .htaccess file; if you do not want to
   use the .htaccess file, or this script is in a folder with other
   files then you will need to configure your server manually.
   <IfModule mod_rewrite.c>
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !index.php
   RewriteRule .* index.php?q=$0 [L]
   </IfModule>

4. Edit your resume
   Point your browser at: http://domain.com/path/to/edit/ [OR]
   you can use direct URL: http://domain.com/path/to/index.php?q=edit

Your resume is accessible at the following URL:
  http://domain.com/path/to/index.php
BUT you can also use these thanks to the rewrite rules:
  http://domain.com/path/to/read/
  http://domain.com/path/to/

So if you had the magical domain myresume.com:
  Install   http://myresume.com/index.php?q=install
  Edit      http://myresume.com/edit/
  Read      http://myresume.com/read/ [OR]
            http://myresume.com/


--- CUSTOMISATION ---
You can customise the PR reading layout by writing your own theme class.
Theme classes are free form but MUST conform to the same API as the
'PRTheme_Default' class which you can see in the code below, essentially:

class THEME_NAME {
	const themeName = 'PR Default Theme';
	const fluidTheme = false;
	public static function stylesheet( $data, $return );
	public static function printsheet( $data, $return );
	public static function contacted( $data, $return );
	public static function render( $data, $return,
		$contactSuccess, $contactError );
}

Once your theme is ready add it to the $_PR_THEMES global array and it
will appear in the configuration screen. The theme should fit within
the Twitter Bootstrap <div class="container"></div> tags.


--- IF YOU LIKE IT ---
If you like this script, and hey it might help you get that dream job,
then I'd love it if you could either let me know you're using it:
  http://andrewbevitt.com/contact/

You can support my work by donating http://andrewbevitt.com/donations/.

   
--- LICENSE ---
The MIT License (MIT)

Copyright (c) 2013 Andrew Bevitt

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

****************************************************************************/

// Buffer the content so we can set cookies later
ob_start();

// Do we have any GET parameters - assume read mode if none
$prQ = isset( $_GET['q'] ) && trim( $_GET['q'] ) ? trim( $_GET['q'], '/' ) : 'read';
if ( ! preg_match('/^(read|edit|install|contact|print|download)$/', $prQ) ) {
	// Not a valid q term so error out
	die("ERROR: Invalid query parameter '" . $prQ . "' - should be one of 'read', 'edit', 'install', 'contact', 'print' or 'download'");
}

// .htaccess file content
$htaccess = '# BEGIN_PR
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !index.php
  RewriteRule .* index.php?q=$0 [QSA,L]
</IfModule>
# END_PR
';

// A few placeholders for repeated definitions
$http = isset( $_SERVER['HTTPS'] ) && strlen( $_SERVER['HTTPS'] ) > 0 ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$uri  = preg_replace( '/\?.*$/', '', $_SERVER['REQUEST_URI'] );
$uri  = preg_replace( '/index\.php/', '', $uri );
define( 'READ_URL', preg_replace( '/((read|edit|install|contact|print|download)\/?)$/', '', $http.$host.$uri ) );
define( 'CONTACT_URL', READ_URL . 'contact/' );
define( 'PRINT_URL', READ_URL . 'print/' );
define( 'DOWNLOAD_URL', READ_URL . 'download/' );
define( 'REGEX_URL', null );
define( 'PDF_NONE', 0 );
define( 'PDF_PRINCE', 1 );
define( 'PDF_WKHTML', 2 );
define( 'DEFAULT_THEME', 100 );
$_PR_THEMES = array( // class names
	DEFAULT_THEME => 'PRTheme_Default',
);

// Section block layout definitions
define( 'SECTION_BLOCK_100', 100 );
define( 'SECTION_BLOCK_70_30', 101 );
define( 'SECTION_BLOCK_30_70', 102 );
define( 'SECTION_BLOCK_30_40_30', 103 );
$_PR_SECTIONS = array(
	SECTION_BLOCK_100 => '100%',
	SECTION_BLOCK_70_30 => '70% | 30%',
	SECTION_BLOCK_30_70 => '30% | 70%',
	SECTION_BLOCK_30_40_30 => '30% | 40% | 30%',
);

/**
 * Get either a Gravatar URL or complete image tag for a specified email address.
 *
 * @param string $email The email address
 * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
 * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
 * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
 * @param boole $img True to return a complete IMG tag False for just the URL
 * @param array $atts Optional, additional key/value attributes to include in the IMG tag
 * @return String containing either just a URL or a complete image tag
 * @source http://gravatar.com/site/implement/images/php/
 */
function get_gravatar( $email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array() ) {
	$url = 'http://www.gravatar.com/avatar/';
	$url .= md5( strtolower( trim( $email ) ) );
	$url .= "?s=$s&d=$d&r=$r";
	if ( $img ) {
		$url = '<img src="' . $url . '"';
		foreach ( $atts as $key => $val )
			$url .= ' ' . $key . '="' . $val . '"';
		$url .= ' />';
	}
	return $url;
}

/**
 * Parse the text with *limited* markdown support.
 * Derived from https://gist.github.com/Xeoncross/2244152
 * but stripped down for plain simple markup supported in
 * PR. Markdown syntax remains broadly compatible.
 *
 * @param string $text
 * @return string
 */
function markdown_limited($text)
{
	// PR customisation: preformat the text to make sure new lines
	// match the anticipated structure in the regular expressions.
	$text = preg_replace( "/\r\n/", "\n", $text );
	$text = preg_replace( "/^([^\n]+\n)/", "\n$1", $text );
	if ( ! preg_match( "/\n$/", $text ) )
		$text .= "\n";

	// Make it HTML safe for starters
	$text = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );

	// Replace for spaces with a tab (for lists and code blocks)
	$text = str_replace( "    ", "\t", $text );

	// Blockquotes (they have email-styled > at the start)
	$regex = '^&gt;.*?$(^(?:&gt;).*?\n|\n)*';
	preg_match_all( "~$regex~m", $text, $matches, PREG_SET_ORDER );
	foreach( $matches as $set ) {
		$block = "<blockquote>". trim( preg_replace( '~(^|\n)[&gt; ]+~', " ", $set[0] ) ) . "</blockquote>\n";
		$text = str_replace( $set[0], $block, $text );
	}

	// Titles
	$text = preg_replace_callback( "~(^|\n)(#{1,6}) ([^\n#]+)[^\n]*~", function( $match ) {
			$n = strlen( $match[2] );
			return "\n<h$n>". $match[3]. "</h$n>";
		}, $text );

	// Lists must start with a tab (four spaces are converted to tabs ^above^)
	$regex = '(?:^|\n)(?:\t+[\-\+\*0-9.][^\n]+\n+)+';
	preg_match_all( "~$regex~", $text, $matches, PREG_SET_ORDER );

	// Recursive closure
	$list = function( $block, $top_level=false ) use ( &$list ) {
		// Extract the whole matched text from the match array
		if ( is_array( $block ) ) $block = $block[0];

		// Chop one level of all the lines
		$block = preg_replace( "~(^|\n)\t~", "\n", $block );

		// Is this an ordered or un-ordered list?
		$tag = ctype_digit( substr( ltrim( $block ), 0, 1 ) ) ? 'ol' : 'ul';

		// Only replace elements of THIS LEVEL with li
		$block = preg_replace( '~(?:^|\n)[^\s]+ ([^\n]+)~', "\n<li>$1</li>", $block );

		// Put all of the elements at this level into the block
		if ( $top_level ) $block .= "\n";
		$block = "<$tag>$block</$tag>";

		// Replace nested list items now
		// NOTE: This means we get code like this:
		// <ul>
		//   <li>Item</li>
		//   <ol>
		//     <li>Sub-item</li>
		//   </ol>
		// </ul>
		$block = preg_replace_callback( '~(\t[^\n]+\n?)+~', $list, $block );

		// return the finished list
		return $top_level ? "\n$block\n\n" : $block;
	};

	// Loop over all list blocks
	foreach( $matches as $set ) {
		$text = str_replace( $set[0], $list( trim( $set[0], "\n " ), true ), $text );
	}

	// Lines that end in two spaces require a BR
	// PR customisation: moved before <p> so takes precedence and
	// to remove the \n after <br> tags so can be inside <p> tags
	$text = str_replace( "  \n", "<br>", $text );

	// Paragraphs
	// PR customisation (added ?) so regex is not greedy on \n's
	// and obviously to include the <br>'s that are added above
	$text = preg_replace( '~\n(([^><\t]|<br>)+?)\n~', "\n\n<p>$1</p>\n\n", $text );
	$text = str_replace( array( "<p>\n", "\n</p>" ), array( '<p>', '</p>' ), $text );

	// Bold, Italic, Code
	$regex = '([*_`])((?:(?!\1).)+)\1';
	preg_match_all( "~$regex~", $text, $matches, PREG_SET_ORDER );
	foreach( $matches as $set ) {
		if ( $set[1] == '`' ) $tag = 'code';
		elseif ( $set[1] == '*' ) $tag = 'b';
		else $tag = 'em';
		$text = str_replace( $set[0], "<$tag>{$set[2]}</$tag>", $text );
	}

	// Links and Images
	$regex = '(!)*\[([^\]]+)\]\(([^\)]+?)(?: &quot;([\w\s]+)&quot;)*\)';
	preg_match_all( "~$regex~", $text, $matches, PREG_SET_ORDER );
	foreach($matches as $set) {
		$title = isset( $set[4] ) ? " title=\"{$set[4]}\"" : '';
		if ( $set[1] ) {
			$text = str_replace( $set[0], "<img src=\"{$set[3]}\"$title alt=\"{$set[2]}\"/>", $text );
		} else {
			$text = str_replace( $set[0], "<a href=\"{$set[3]}\"$title>{$set[2]}</a>", $text );
		}
	}

	// Preformated blocks
	// PR customisation to remove the <span class="string|comment"> tags
	$regex = '(?:(?:(    |\t)[^\n]*\n)|\n)+';
	preg_match_all( "~$regex~", $text, $matches, PREG_SET_ORDER );
	foreach( $matches as $set ) {
		// If it's a blank line then ignore it
		if ( ! trim( $set[0] ) ) continue;

		// If any tags were added (i.e. <p></p>), remove them!
		$lines = strip_tags( $set[0] );

		// Remove the starting tab from each line
		$lines = trim( str_replace( "\n\t", "\n", $lines ), "\n" );
		$text = str_replace( $set[0], "\n<pre>". $lines. "</pre>\n", $text );
	}

	// Reduce crazy newlines
	return preg_replace("~\n\n\n+~", "\n\n", $text);
}

// Sanitize output
function pr_sanitize( $str ) {
	// Trim whitespace
	$str = trim( $str );
	// Escape HTML
	$str = htmlspecialchars( $str );
	// Output
	return $str;
}

// Convert Markdown to HTML markup
function pr_markup( $str ) {
	// The markdown_limited function assumes new lines are just \n
	$str = preg_replace( "/\r\n/", "\n", $str );
	return markdown_limited( $str );
}

// Split content based on the <!--COLBREAK--> delimiter
// and returns the indexed entry. If the index does not
// exist then an empty string is returned.
function pr_split( $str, $idx ) {
	$splits = preg_split( '/<\!\-\-COLBREAK\-\-\>/', $str );
	if ( count( $splits ) > $idx )
		return $splits[$idx];
	return '';
}

// Replace option placeholders {{option_name}} with value
function pr_option_replace( $content, $prData ) {
	preg_match_all( '/{{([a-zA-Z0-9_]+)}}/', $content, $matches, PREG_PATTERN_ORDER );
	if ( count( $matches ) > 0 ):
		$nummatch = count( $matches[0] );
		for( $i=0; $i<$nummatch; $i++) {
			$v = $prData->config( $matches[1][$i] );
			if ( $v !== null )
				$content = str_replace( $matches[0][$i], $v, $content );
		}
	endif;
	// Return the modified content string
	return $content;
}

// Helper for sorting POST data from the section rows below
function section_row_sort( $left, $right ) {
	if ( ! ( isset( $left['order'] ) && isset( $right['order'] ) ) )
		return 0; // assume correct order if not valid
	if ( $left['order'] == $right['order'] ) return 0;
	return ( $left['order'] < $right['order'] ) ? -1 : 1;
}

// Return a editable section form row
function edit_section_row( $code, $sort='', $type=SECTION_BLOCK_70_30, $heading='', $content='' ) {
?>
	<div class="pr-section-row" id="section-row-<?php echo $code; ?>">
	<div class="control-group">
		<label for="sections[<?php echo $code; ?>][order]" class="control-label">Order</label>
		<div class="controls">
			<input type="text" id="sections[<?php echo $code; ?>][order]" name="sections[<?php echo $code; ?>][order]" value="<?php echo $sort; ?>" class="section-order">
			<label for="sections[<?php echo $code; ?>][delete]">
				<input type="checkbox" value="1" id="sections[<?php echo $code; ?>][delete]" name="sections[<?php echo $code; ?>][delete]" class="section-delete" />
				Delete section
			</label>
			<span class="help-block">Specify a sortable value to order your sections</span>
		</div>
	</div>
	<div class="control-group">
		<label for="sections[<?php echo $code; ?>][type]" class="control-label">Layout type</label>
		<div class="controls">
			<select id="sections[<?php echo $code; ?>][type]" name="sections[<?php echo $code; ?>][type]">
<?php
	foreach( $GLOBALS['_PR_SECTIONS'] as $key=>$opt )
		printf( '<option value="%s"%s>%s</option>', $key, $key==$type ? ' selected' : '', $opt );
?>
			</select>
			<span class="help-block">Content column structure can be specified here and theme will render as per its template.</span>
		</div>
	</div>
	<div class="control-group">
		<label for="sections[<?php echo $code; ?>][heading]" class="control-label">Heading</label>
		<div class="controls">
			<input type="text" id="sections[<?php echo $code; ?>][heading]" name="sections[<?php echo $code; ?>][heading]" class="span6" value="<?php echo $heading; ?>">
			<span class="help-block">Section heading <code>&lt;h3&gt;&lt;/h3&gt;</code> - leave blank for none</span>
		</div>
	</div>
	<div class="control-group">
		<label for="sections[<?php echo $code; ?>][content]" class="control-label">Content</label>
		<div class="controls">
			<textarea id="sections[<?php echo $code; ?>][content]" name="sections[<?php echo $code; ?>][content]" class="span6" rows="10"><?php echo $content; ?></textarea>
			<span class="help-block">If using a layout with multiple columns put <code>&lt;!--COLBREAK--&gt;</code> between blocks.<br/>Section content can be written in Markdown.</span>
		</div>
	</div>
	</div>
<?php
} // edit_section_row


// Default PR theme
class PRTheme_Default {
	// Theme name is constant
	const themeName = 'PR Default Theme';

	// Is the theme fixed widths or fluid width?
	const fluidTheme = false;

	// Static method for rendering inline styles for this theme
	public static function stylesheet( $prData, $return=false ) {
		ob_start(); // buffer output so we can flush or return on end
?>
		#gravatar { text-align: center; margin-top: 10px; }
		.pr-top-10 { margin-top: 10px; }
		#topline { margin-bottom: 20px; }
		.prRow hr, hr.clearfix { border-width: 0; margin:0; clear: both; width: 100%; }
		.no-list-item { list-style-type:none;margin:0;padding:0; }
		.pr-contact-list { margin-left:5px;margin-top:5px;font-size: 85%; }
		.pr-contact-list strong { text-transform:uppercase; }
		div.pr-qr-code { margin-top: 20px; }
		.pr-printable, .pr-downloadable { text-transform:uppercase; }

		/* Large desktop */
		@media (min-width: 1200px) { }
		/* Portrait tablet to landscape and desktop */
		@media (min-width: 768px) and (max-width: 979px) { }
		/* Landscape phone to portrait tablet */
		@media (max-width: 767px) {
			#gravatar { width: 50%; float: right; text-align:right; }
			#topline { width: 50%; float: left; white-space: nowrap; }
			.prRow .pr50 { float: left; width: 50%; }
			#prPower { margin-left:-20px; margin-right:-20px; padding-left:20px; padding-right:20px; }
		}
		/* Landscape phones and down */
		@media (max-width: 480px) {
			#gravatar { display: none }
			#topline { width: 100%; float: none; white-space:normal; }
			.prRow .pr50 { width: 100%; float: none; }
			.pr-printed .prRow .pr50 { float: left; width: 50%; }
		}

		/* Overwrite when in print preview mode */
		.pr-print-only { display: none; }
		.pr-printed #gravatar { display: block; }
		.pr-printed #topline { white-space: nowrap; }
		.pr-printed .pr-print-only { display: block; }
		.pr-printed .pr-not-on-print { display: none; }
		.pr-printed .pr-print-avoid-break { page-break-inside: avoid; }
		.pr-printed .pr-contact-list a[href]:after{content:"";}
<?php
		if ( $return ) return ob_get_clean();
		ob_end_flush();
	}

	// Static method for rendering inline print styles for this theme
	public static function printsheet( $prData, $return=false ) {
		ob_start(); // buffer output so we can flush or return on end
?>
		#gravatar { display: block; }
		#topline { white-space: nowrap; }
		@media (max-width: 480px) { .prRow .pr50 { float: left; width: 50%; } }
		.pr-print-only { display: block; }
		.pr-not-on-print { display: none; }
		.pr-print-avoid-break { page-break-inside: avoid; }
		.pr-contact-list a[href]:after{content:"";}
<?php
		if ( $return ) return ob_get_clean();
		ob_end_flush();
	}

	// Returns the top line of the resume or prints
	private static function topline( $prData, $return=false ) {
		ob_start(); // buffer output so we can flush or return on end
		$printMode = $GLOBALS['prQ'] === 'print';
?>
	<div class="row">
		<div class="span2" id="gravatar">
			<img class="img-circle" src="<?php echo get_gravatar($prData->config('owner_email'), 104); ?>" alt="Photo of <?php echo $prData->config('owner_name'); ?>"/>
		</div>
		<div class="span10" id="topline">
			<div class="row">
				<div class="span<?php echo $printMode ? '6':'7'; ?>">
<?php
	// The name and tagline should go here
	printf( '<h1>%s</h1>', $prData->config( 'owner_name' ) );
	$tagline = $prData->resume( 'tagline' );
	if ( ! empty( $tagline ) )
		printf( '<h2>%s</h2>', $tagline );
?>
				</div>
				<div class="span<?php echo $printMode ? '4':'3'; ?> pr-top-10">
<?php
	// Social media icons and contact methods
	if ( $prData->anySocial() ):
		printf('<div class="btn-group pr-social-icons pr-not-on-print">');
		foreach( explode(',', 'facebook,github,google_plus,linkedin,pinterest,twitter') as $social ) {
			$social_link = $prData->config( $social . '_profile' );
			if ( ! empty( $social_link ) )
				printf('<a class="btn" href="%s"><i class="icon-%s"></i></a>',
					$social_link, str_replace( '_', '-', $social ));
		}
		printf('</div>');
	endif;
	// and the list of contact details
	if ( $prData->anyContact() ):
		printf('<ul class="pr-contact-list no-list-item">');
		$email = $prData->config( 'public_email_address' );
		if ( ! empty( $email ) )
			printf( '<li><a href="mailto:%1$s">%1$s</a></li>', $email );
		$website = $prData->config( 'website' );
		if ( ! empty( $website ) )
			printf( '<li><a href="%1$s">%1$s</a></li>', $website );
		foreach( array( 'phone_number', 'location' ) as $key ) {
			$dopt = $prData->config( $key );
			if ( ! empty( $dopt ) )
				printf( '<li>%s</li>', $dopt );
		}
		printf('</ul>');
	endif;
?>
				</div>
			</div>
		</div><!-- topline -->
	</div>
<?php
		if ( $return ) return ob_get_clean();
		ob_end_flush();
	}

	// Static method for rendering the post contact form message
	public static function contacted( $prData, $return=false ) {
		ob_start(); // buffer output so we can flush or return on end
		echo self::topline( $prData, true );
		printf( '<div class="row"><div class="span12 alert alert-success">%s</div></div>',
			pr_markup( $prData->config( 'post_contact_message' ) ) );
		printf( '<div class="row"><div class="span12"><a href="%s" title="%s"><i class="icon-arrow-left"></i> Back to %2$s</a></div></div>',
			READ_URL, $prData->resume( 'title' ) );
		if ( $return ) return ob_get_clean();
		ob_end_flush();
	}

	// Static method for rendering an instance of PRData
	// Will be called to render inside a <div class="container"></div>
	// or <div class="container-fluid"></div> depending on fluidTheme.
	public static function render( $prData, $return=false, $contactSuccess=true, $contactError=null ) {
		ob_start(); // buffer output so we can flush or return on end
		$printMode = $GLOBALS['prQ'] === 'print';
		// Decide if downloading is enabled?
		if ( $prData->config( 'downloadable_pdf' ) != PDF_NONE ):
?>
	<div class="row pr-not-on-print">
		<div class="alert">
			<div class="span2 offset4 hidden-phone text-center pr-printable"><a href="<?php echo PRINT_URL; ?>" title="Print Resume">Print</a></div>
			<div class="span2 text-center pr-downloadable"><a href="<?php echo DOWNLOAD_URL; ?>" title="Download PDF">Download</a></div>
<?php
		else:
?>
	<div class="row pr-not-on-print hidden-phone">
		<div class="alert">
			<div class="span2 offset5 text-center pr-printable"><a href="<?php echo PRINT_URL; ?>" title="Print Resume">Print</a></div>
<?php
		endif; // downloadable
?>
			<hr class="clearfix"/>
		</div>
	</div>
<?php
		// If the contact form failed to process output an error here
		if ( ! $contactSuccess ):
?>
	<div class="row pr-not-on-print">
		<div class="alert alert-error text-center"><strong><?php echo pr_sanitize( $contactError ); ?></strong></div>
	</div>
<?php
		endif;

		// Output the topline
		echo self::topline( $prData, true );

		// Is there an objective specified?
		$objective = $prData->resume( 'objective_profile' );
		if ( ! empty( $objective ) )
			printf( '<div class="row"><div class="%s"><p class="lead">%s</p></div></div>', $printMode ? 'span12' : 'span10 offset2', $objective );
	
		// Now we need to loop over all the sections rendering as needed
		foreach( $prData->resume( 'sections' ) as $section ) {
			// Layout is style dependent so act based on that
			printf( '<div class="row pr-print-avoid-break">' );
			switch( $section['style'] ) {
				case SECTION_BLOCK_100:
					if ( ! empty( $section['heading'] ) )
						printf('<div class="%s"><h3>%s</h3></div>', $printMode ? 'span12' : 'span10 offset2', $section['heading']);
					if ( ! empty( $section['content'] ) )
						printf('<div class="%s">%s</div>', $printMode ? 'span12' : 'span10 offset2', $section['content']);
					break;
				case SECTION_BLOCK_70_30:
					if ( ! empty( $section['heading'] ) )
						printf('<div class="%s"><h3>%s</h3></div>', $printMode ? 'span12' : 'span10 offset2', $section['heading']);
					printf('<div class="%s">%s</div><div class="%s">%s</div>',
						$printMode ? 'span8' : 'span7 offset2', $section['content'][0],
						$printMode ? 'span4' : 'span3', $section['content'][1]);
					break;
				case SECTION_BLOCK_30_70:
					if ( ! empty( $section['heading'] ) )
						printf('<div class="%s"><h3>%s</h3></div>', $printMode ? 'span12' : 'span10 offset2', $section['heading']);
					printf('<div class="%s">%s</div><div class="%s">%s</div>',
						$printMode ? 'span4' : 'span3 offset2', $section['content'][0],
						$printMode ? 'span8' : 'span7', $section['content'][1]);
					break;
				case SECTION_BLOCK_30_40_30:
					// This one is quite different - heading in column 1
					printf('<div class="%s">', $printMode ? 'span4' : 'span3 offset2');
					if ( ! empty( $section['heading'] ) )
						printf('<h3>%s</h3>', $section['heading']);
					printf('<div>%s</div></div><div class="%s"><div class="row prRow"><div class="pr50 %s">%s</div><div class="pr50 %s">%s</div><hr/></div></div>',
						$section['content'][0],
						$printMode ? 'span8' : 'span7',
						$printMode ? 'span4' : 'span4', $section['content'][1],
						$printMode ? 'span4' : 'span3', $section['content'][2]);
					break;
				default:
					printf( '<div class="alert alert-error">Unknown section layout</div>' );
			}
			printf( '</div>' ); // row
		}

		// Do we want a contact form?
		// NOTE: Not shown on printouts so dont render
		if ( $prData->config( 'contact_form' ) && !$printMode ):
?>
	<div class="row pr-not-on-print">
		<div class="span10 offset2">
			<h3><?php echo $prData->config( 'contact_form_heading' ); ?></h3>
		</div>
<!--		<div class="span4 offset2 hidden-phone">
<?php
			// Social media icons and contact methods
			if ( $prData->anySocial() ):
				printf('<div class="btn-group pr-social-icons pr-not-on-print">');
				foreach( explode(',', 'facebook,github,google_plus,linkedin,pinterest,twitter') as $social ) {
					$social_link = $prData->config( $social . '_profile' );
					if ( ! empty( $social_link ) )
						printf('<a class="btn" href="%s"><i class="icon-%s"></i></a>',
							$social_link, str_replace( '_', '-', $social ));
				}
				printf('</div>');
			endif;
			// and the list of contact details
			if ( $prData->anyContact() ):
				printf('<ul class="pr-contact-list no-list-item">');
				$email = $prData->config( 'public_email_address' );
				if ( ! empty( $email ) )
					printf( '<li><a href="mailto:%1$s">%1$s</a></li>', $email );
				$website = $prData->config( 'website' );
				if ( ! empty( $website ) )
					printf( '<li><a href="%1$s">%1$s</a></li>', $website );
				foreach( array( 'phone_number', 'location' ) as $key ) {
					$dopt = $prData->config( $key );
					if ( ! empty( $dopt ) )
						printf( '<li>%s</li>', $dopt );
				}
				printf('</ul>');
			endif;
?>
		</div>-->
	<form method="post" action="<?php echo CONTACT_URL; ?>" class="pr-contact-form">
		<div class="span3 offset2">
			<ul class="no-list-item">
				<li><input type="text" placeholder="Your name" name="prconf_name" class="span3" value="<?php echo empty($_POST['prconf_name'])?'':htmlspecialchars($_POST['prconf_name']); ?>" required/></li>
				<li><input type="text" placeholder="Your contact details" name="prconf_contact" class="span3" value="<?php echo empty($_POST['prconf_contact'])?'':htmlspecialchars($_POST['prconf_contact']); ?>" required/></li>
				<li><input type="text" placeholder="Message subject" name="prconf_subject" class="span3" value="<?php echo empty($_POST['prconf_subject'])?'':htmlspecialchars($_POST['prconf_subject']); ?>" required></li>
				<li><input type="text" placeholder="Where are you from?" name="prconf_where" class="span3" value="<?php echo empty($_POST['prconf_where'])?'':htmlspecialchars($_POST['prconf_where']); ?>"/></li>
			</ul>
		</div>
		<div class="span4">
			<textarea id="prconf_message" name="prconf_message" placeholder="Enter your message here" class="span4" rows="4" required><?php echo empty($_POST['prconf_message'])?'':htmlspecialchars($_POST['prconf_message']); ?></textarea>
			<button type="submit" class="btn btn-primary btn-block">Send</button>
		</div>
		<div class="span3 hidden-phone">
<?php
			// Social media icons and contact methods
			if ( $prData->anySocial() ):
				printf('<div class="btn-group pr-social-icons pr-not-on-print">');
				foreach( explode(',', 'facebook,github,google_plus,linkedin,pinterest,twitter') as $social ) {
					$social_link = $prData->config( $social . '_profile' );
					if ( ! empty( $social_link ) )
						printf('<a class="btn" href="%s"><i class="icon-%s"></i></a>',
							$social_link, str_replace( '_', '-', $social ));
				}
				printf('</div>');
			endif;
			// and the list of contact details
			if ( $prData->anyContact() ):
				printf('<ul class="pr-contact-list no-list-item">');
				$email = $prData->config( 'public_email_address' );
				if ( ! empty( $email ) )
					printf( '<li><a href="mailto:%1$s">%1$s</a></li>', $email );
				$website = $prData->config( 'website' );
				if ( ! empty( $website ) )
					printf( '<li><a href="%1$s">%1$s</a></li>', $website );
				foreach( array( 'phone_number', 'location' ) as $key ) {
					$dopt = $prData->config( $key );
					if ( ! empty( $dopt ) )
						printf( '<li>%s</li>', $dopt );
				}
				printf('</ul>');
			endif;
?>
		</div>
	</form>
	</div>
<?php
		endif; // contact form

		// Do we want a QR code?
		// NOTE: Using the deprecated infographic component of the charts api
		if ( $prData->config( 'print_qrcode' ) ):
?>
	<div class="row pr-print-only pr-qr-code prRow pr-print-avoid-break">
		<div class="pr50 <?php echo $printMode ? 'span8' : 'span7 offset2'; ?>">
<?php
			print( pr_markup( pr_option_replace( $prData->config( 'print_text' ), $prData ) ) );
?>
		</div>
		<div class="pr50 text-center <?php echo $printMode ? 'span4' : 'span3'; ?>">
<?php
			printf( '<img src="https://chart.googleapis.com/chart?chs=104x104&cht=qr&chld=Q|0&chl=%s" alt="QR Code"/>',
				urlencode( $prData->config( 'qrcode_url' ) ) );
?>
		</div>
	</div>
<?php
		endif; // QR Code
?>
	<div class="row hidden-phone pr-not-on-print">
		<div class="alert">
<?php
		// Decide if downloading is enabled?
		if ( $prData->config( 'downloadable_pdf' ) != PDF_NONE ):
?>
		<div class="span2 offset4 hidden-phone text-center pr-printable"><a href="<?php echo PRINT_URL; ?>" title="Print Resume">Print</a></div>
		<div class="span2 text-center pr-downloadable"><a href="<?php echo DOWNLOAD_URL; ?>" title="Download PDF">Download</a></div>
<?php
		else:
?>
		<div class="span2 offset5 hidden-phone text-center pr-printable"><a href="<?php echo PRINT_URL; ?>" title="Print Resume">Print</a></div>
<?php
		endif; // downloadable
?>
		<hr class="clearfix"/>
		</div>
	</div>
<?php
		// If want the output returned do that otherwise flush
		if ( $return ) return ob_get_clean();
		ob_end_flush();
	}
}

// Configuration option manager - renderer and default values
class PROption {
	// List of known option types
	const knownTypes = '/^(text|url|email|password|longtext|radio|checkbox|dropdown)$/';
	
	// Create a new option instance
	function __construct( $name, $title, $type, $default, $required=false,
			$options=null, $validator=null, $helpText='' ) {
		$this->optionName = $name;
		$this->optionTitle = $title;
		// Verify the type matches one of the known types
		if ( ! preg_match( self::knownTypes, $type ) )
			die( "ERROR: Invalid option type '" . $type . "' given" );
		$this->optionType = $type;
		$this->_default = $default;
		$this->_value = null;
		$this->_valueSet = false;
		$this->required = $required;
		// Are there any options values required?
		$this->options = array();
		if ( preg_match( '/^(radio|checkbox|dropdown)$/', $type ) && is_array( $options ) )
			$this->options = $options;
		// Validation regular expression
		$this->regex = $validator;
		$this->help = $helpText;
	}

	// Renders the option as a for for the form
	function render( $return=false ) {
		$callable = 'render_' . $this->optionType;
		return $this->$callable( $return );
	}

	// Returns TRUE if the option name matches given key
	function is( $key ) {
		return $this->optionName === $key;
	}

	// Returns the option name
	function key() {
		return $this->optionName;
	}

	// Returns TRUE if the value is the default value
	function isDefault() {
		// Use != here because 1 == "1" when POSTing
		return ! ( $this->_valueSet && $this->_value != $this->_default );
	}

	// Set the value to the given value
	function set( $new ) {
		// If the new value is "empty" then consider this an "unset"
		$this->_valueSet = ! empty( $new );
		$this->_value = $this->_valueSet ? $new : null;
	}

	// Return the current or default value
	function value() {
		if ( $this->_valueSet ) return $this->_value;
		return $this->_default;
	}

	// Returns TRUE if value is valid
	function validate() {
		if ( $this->regex !== null && ! $this->isDefault() )
			return preg_match( $this->regex, $this->_value );
		return true;
	}

	// Renderer for text options
	function render_text( $return=false, $type='text' ) {
		$out = sprintf('<div class="control-group%6$s"><label class="control-label" for="%1$s">%2$s</label><div class="controls"><input class="span6" type="%8$s" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s"%7$s><span class="help-block">%5$s</span></div></div>',
			$this->optionName, $this->optionTitle,
			$this->isDefault() ? '' : $this->value(),
			$this->_default, $this->help,
			$this->validate() ? '' : ' error',
			$this->required ? ' required' : '', $type);
		if ( $return ) return $out;
		print( $out );
	}

	// Renderer for URL options
	function render_url( $return=false ) {
		return $this->render_text( $return, 'text' );
	}

	// Renderer for RADIO select options
	function render_radio( $return=false ) {
		$out = sprintf('<div class="control-group%2$s"><label class="control-label">%1$s</label><div class="controls">', $this->optionTitle, $this->validate() ? '' : ' error');
		foreach( $this->options as $val=>$opt )
			$out .= sprintf('<label class="radio inline"><input type="radio" id="%1$s_%2$s" name="%1$s" value="%2$s"%4$s> %3$s</label>', $this->optionName, $val, $opt, $this->value() == $val ? ' checked' : '');
		$out .= sprintf('<span class="help-block">%s</span></div></div>', $this->help);
		if ( $return ) return $out;
		print( $out );
	}

	// Renderer for CHECKBOX select options
	function render_checkbox( $return=false ) {
		$out = sprintf('<div class="control-group%2$s"><label class="control-label">%1$s</label><div class="controls">', $this->optionTitle, $this->validate() ? '' : ' error');
		foreach( $this->options as $val=>$opt )
			$out .= sprintf('<label class="checkbox"><input type="checkbox" id="%1$s_%2$s" name="%1$s" value="%2$s"%4$s> %3$s</label>', $this->optionName, $val, $opt, $this->value() == $val ? ' checked' : '');
		$out .= sprintf('<span class="help-block">%s</span></div></div>', $this->help);
		if ( $return ) return $out;
		print( $out );
	}

	// Renderer for SELECT select options
	function render_dropdown( $return=false ) {
		$out = sprintf('<div class="control-group%3$s"><label class="control-label" for="%1$s">%2$s</label><div class="controls"><select class="span6" id="%1$s" name="%1$s">%4$s',
			$this->optionName, $this->optionTitle,
			$this->validate() ? '' : ' error',
			$this->required ? '' : '<option value="">-----</option>');
		foreach( $this->options as $val=>$opt )
			$out .= sprintf('<option value="%s"%s>%s</option>',
				$val, $this->value() == $val ? ' selected' : '', $opt);
		$out .= sprintf('</select><span class="help-block">%s</span></div></div>', $this->help);
		if ( $return ) return $out;
		print( $out );
	}

	// Renderer for email text options
	function render_email( $return=false ) {
		return $this->render_text( $return, 'email' );
	}

	// Renderer for long text (textbox) options
	function render_longtext( $return=false ) {
		$out = sprintf('<div class="control-group%6$s"><label class="control-label" for="%1$s">%2$s</label><div class="controls"><textarea class="span6" rows="4" id="%1$s" name="%1$s" placeholder="%7$s"%5$s>%3$s</textarea><span class="help-block">%4$s</span></div></div>',
			$this->optionName, $this->optionTitle,
			$this->isDefault() ? '' : $this->value(),
			$this->help, $this->required ? ' required' : '',
			$this->validate() ? '' : ' error', $this->_default);
		if ( $return ) return $out;
		print( $out );
	}

	// Renderer for password options
	function render_password( $return=false ) {
		return $this->render_text( $return, 'password' );
	}
}


// Data store manager - basically a key value store
class PRData {
	function __construct( $dataStore, $cryptSalt ) {
		$this->dataStore = $dataStore;
		$this->cryptSalt = $cryptSalt;
		$this->_keyToken = null;
		$this->loaded = false;

		// Configuration options in render order
		// name, title, type, default, required, options, regex, help_text
		$this->_config = array(
			new PROption( 'owner_name', 'Your name', 'text', 'Anonymous', true,
				null, null, 'Your name as you want it to appear on your resume' ),
			new PROption( 'owner_email', 'Email address', 'email', '', true,
				null, null, 'Contact email address for gravatar and contact form' ),
			new PROption( 'theme', 'Theme', 'dropdown', DEFAULT_THEME, true,
				$GLOBALS['_PR_THEMES'], null, 'Choose the look and feel of your resume' ),
			new PROption( 'contact_form', 'Include a contact form', 'checkbox', 0,
				false, array( 1 => 'Yes please!' ), null,
				'If enabled a contact form will appear on your resume' ),
			new PROption( 'contact_form_heading', 'Heading for contact form', 'text', 'Get in touch',
				false, null, null, 'A heading to be displayed above the contact form' ),
			new PRoption( 'post_contact_message', 'After contact message', 'longtext', 'Thanks! I might get back to you...',
				false, null, null, 'Message to display after someone uses the contact form.' ),
			new PROption( 'downloadable_pdf', 'PDF Generator', 'radio', PDF_NONE, false,
				array( PDF_NONE => 'None / disabled', PDF_PRINCE => 'Prince XML', PDF_WKHTML => 'WKHTMLTOPDF' ),
				null, 'If your server supports PDF generation enable it here.<br/>WKHTMLTOPDF gives much better results for the default theme.' ),
			new PROption( 'public_email_address', 'Public email address', 'email', '', false,
				null, null, 'Email address to be displayed in plain text on resume' ),
			new PROption( 'phone_number', 'Phone number', 'text', '', false,
				null, null, 'Phone number to be displayed on resume' ),
			new PROption( 'location', 'Your location', 'text', '', false,
				null, null, 'Where you live - displayed publically on resume' ),
			new PROption( 'website', 'Website', 'url', '', false,
				null, null, 'Your personal website - linked to from your resume' ),
			new PROption( 'facebook_profile', '<i class="icon-facebook"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your Facebook profile' ),
			new PROption( 'github_profile', '<i class="icon-github"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your Github profile' ),
			new PROption( 'google_plus_profile', '<i class="icon-google-plus"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your Google+ profile' ),
			new PROption( 'linkedin_profile', '<i class="icon-linkedin"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your LinkedIn profile' ),
			new PROption( 'pinterest_profile', '<i class="icon-pinterest"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your Pinterest profile' ),
			new PROption( 'twitter_profile', '<i class="icon-twitter"></i>', 'url', '',
				false, null, REGEX_URL, 'Link to your Twitter profile' ),
			new PROption( 'print_qrcode', 'Show QR code on print', 'checkbox', 1,
				false, array( 1 => 'That\'d be great' ), null,
				'If enabled a QR code will appear on printed resumes' ),
			new PROption( 'qrcode_url', 'QR code URL', 'url', READ_URL,
				false, null, REGEX_URL, 'If you want QR code present a different URL' ),
			new PROption( 'print_text', 'Text explaining QR code', 'longtext',
				"This resume was printed or downloaded from my web based resume.<br/>" .
				"Scan the QR code or browse to {{qrcode_url}} to view online.",
				false, null, null, 'Use this to direct print resume readers to your ' .
				'online resume. Use the placeholder {{qrcode_url}} for the link.' ),
			new PROption( 'include_google_plus_author', 'Include Google+ Author',
				'radio', 0, false, array( 0 => 'No', 1 => 'Yes' ), null,
				'Should a rel="author" link to your Google+ profile be included?'),
			new PROption( 'pr_footer', 'Note PR use in footer', 'radio', 1,
				false, array( 0 => 'No', 1 => 'Yes' ), null,
				'Include a line at in the footer stating this resume is powered by PR'),
			new PROption( 'self_notes', 'Private notes', 'longtext', '', false,
				null, null, 'Private notes about this resume / configuration'),
		);

		// The hashed password on disk
		$this->hashedPassword = null;

		// Resume data blocks
		$this->_resume = array(
			// <title></title>
			'title' => 'A resume created with PR',
			// meta description
			'description' => 'A resume created with PR',
			// Tagline / professional title
			'tagline' => '',
			// Objective or professional profile
			'objective_profile' => 'Introduce yourself and your resume',
			// Sections are blocks of content:
			//  _new is a special placeholder for defaults/fields
			'sections' => array(
				'_new' => array(
					'heading' => 'Section heading',
					'style' => SECTION_BLOCK_70_30,
					'markdown' => 'Write content in Markdown'
				)
			)
		);
	}

	// Returns TRUE if any of the social media profiles have been set
	function anySocial() {
		foreach( explode(',', 'facebook,github,google_plus,linkedin,pinterest,twitter') as $social ) {
			$opt = $this->config( $social . '_profile' );
			if ( ! empty( $opt ) )
				return true;
		}
		// None active so return false
		return false;
	}

	// Returns TRUE if any of the contact methods have been set
	function anyContact() {
		foreach( explode(',', 'public_email_address,website,phone_number,location') as $contact ) {
			$contact = $this->config( $contact );
			if ( ! empty( $contact ) )
				return true;
		}
		// None specified so return false
		return false;
	}

	// Renders the configuration options
	function renderOptions( $return=false ) {
		$out = '';
		foreach( $this->_config as $option )
			$out .= $option->render( $return );
		if ( $return ) return $out;
	}

	// Updates the option values on disk and in memory
	function saveOptions( $data ) {
		// First set in memory values and validate
		$allValid = true;
		foreach( $this->_config as $option ) {
			if ( array_key_exists( $option->key(), $data ) ):
				$option->set( $data[$option->key()] );
				if ( ! $option->validate() )
					$allValid = false;
			endif;
		}

		// If all valid then write to disk where not defaults
		if ( $allValid ):
			$diskdata = $this->loadData( true );
			if ( $diskdata === null ) return false; // failed to load
			foreach( $this->_config as $option ) {
				// Is default so remove from disk data
				if ( $option->isDefault() ) unset( $diskdata['config'][$option->key()] );
				else // not default so store in disk
					$diskdata['config'][$option->key()] = $option->value();
			}
			// Return the write status 
			return $this->writeData( $diskdata );
		endif;

		// Return validity of the options
		return $allValid;
	}

	// Updates the content values on disk and in memory
	// Returns TRUE on success or an error message on failure.
	function saveContent( $data ) {
		// First extract the details then the sections
		foreach( explode( ',', 'title,description,tagline,objective_profile' ) as $key ) {
			if ( isset( $data['resume_' . $key] ) )
				$this->_resume[$key] = $data['resume_' . $key];
		}
		// And now the sections
		if ( isset( $data['sections'] ) ):
			$new = $this->_resume['sections']['_new'];
			$this->_resume['sections'] = array( '_new' => $new );
			usort( $data['sections'], 'section_row_sort' );
			foreach( $data['sections'] as $tkey=>$section ) {
				// Only save if not deleted
				if ( empty( $section['delete'] ) ) {
					$this->_resume['sections'][$tkey]['heading'] = $section['heading'];
					$this->_resume['sections'][$tkey]['style'] = $section['type'];
					$this->_resume['sections'][$tkey]['markdown'] = $section['content'];
				}
			}
		endif;
		// Now structure into disk array
		$diskdata = $this->loadData( true );
		if ( $diskdata === null ) return "Could not open data store for writing content";
		foreach( $this->_resume as $key=>$value ) {
			if ( $key === 'sections' ):
				$outvalue = $value;
				unset( $outvalue['_new'] );
				$diskdata['resume'][$key] = $outvalue;
			else:
				$diskdata['resume'][$key] = $value;
			endif;
		}
		// and finally write back to disk
		if ( ! $this->writeData( $diskdata ) )
			return "Could not write data store file to disk";
		return true;
	}

	// Changes the stored password
	function newPassword( $pwd ) {
		$this->hashedPassword = $this->saltHash( $pwd );
		$data = $this->loadData( true );
		if ( $data !== null ):
			$data['passwd'] = $this->hashedPassword;
			return $this->writeData( $data );
		endif;
		// Couldn't read the data so can't change password
		return false;
	}

	// Check the data file exists
	function dataStoreExists() {
		return is_file( $this->dataStore );
	}

	// Create the data store file and return TRUE on success
	// NOTE: This is not destructive so the file will not be destroyed on recreate
	function createDataStore( $password ) {
		$made = touch( $this->dataStore ) && is_writable( $this->dataStore );
		if ( ! $made ) return false; // couldn't make file
		// Create a default file contents with the password 
		return $this->writeData( array(
			'config' => array(),
			'resume' => array(),
			'passwd' => $this->saltHash( $password )
		) );
	}

	// Salts and hashes a string (i.e. password)
	function saltHash( $str ) {
		return sha1( sha1( $str ) . ':' . $this->cryptSalt );
	}

	// Write the data to the data store file
	function writeData( $data ) {
		return file_put_contents( $this->dataStore, json_encode( $data ) );
	}

	// Load the data store file and fill the value arrays
	function loadData( $rawreturn=false ) {
		$data = json_decode( file_get_contents( $this->dataStore ), true );
		if ( is_array( $data ) && json_last_error() === JSON_ERROR_NONE ):
			if ( $rawreturn ) return $data;
			// Password and configuration options
			$this->hashedPassword = $data['passwd'];
			foreach( $this->_config as $option ) {
				if ( array_key_exists( $option->key(), $data['config'] ) )
					$option->set( $data['config'][$option->key()] );
			}
			// Load the resume data
			foreach( $this->_resume as $key=>$default ) {
				if ( array_key_exists( $key, $data['resume'] ) ):
					if ( $key === 'sections' )
						$this->_resume[$key] = array_merge( $this->_resume[$key], $data['resume'][$key] );
					else $this->_resume[$key] = $data['resume'][$key];
				endif;
			}
			// Mark data as loaded
			$this->loaded = true;
		else:
			var_dump( $data );
			die( "JSON ERROR: " . json_last_error() );
		endif;
		// Give back NULL if data load failed
		if ( $rawreturn ) return null;
	}

	// Returns TRUE if the data file was parsed successfully
	function ready() {
		$this->loadData();
		return $this->loaded;
	}

	// Returns the configuration value of the given key or null if unknown
	function config($key) {
		foreach( $this->_config as $option ) {
			if ( $option->is( $key ) )
				return $option->value();
		}
		// If not found return null
		return null;
	}

	// Returns the resume content item escaped and sanitized
	function resume($key, $sanitize=true) {
		if ( ! array_key_exists( $key, $this->_resume ) )
			return null;
		$raw = $this->_resume[$key];
		if ( $sanitize ):
			if ( $key === 'sections' ):
				$data = array();
				foreach( $raw as $key=>$section ) {
					if ( $key === '_new' ) continue;
					// Sanitize each heading and content block
					$data[$key]['heading'] = pr_sanitize( $section['heading'] );
					$data[$key]['style'] = $section['style'];
					// Break content based on <!--COLBREAK-->
					switch( $section['style'] ) {
						case SECTION_BLOCK_100:
							$data[$key]['content'] = pr_markup( $section['markdown'] );
							break;
						case SECTION_BLOCK_70_30:
						case SECTION_BLOCK_40_60:
							$data[$key]['content'] = array(
								pr_markup( pr_split( $section['markdown'], 0 ) ),
								pr_markup( pr_split( $section['markdown'], 1 ) ),
							);
							break;
						case SECTION_BLOCK_40_30_30:
							$data[$key]['content'] = array(
								pr_markup( pr_split( $section['markdown'], 0 ) ),
								pr_markup( pr_split( $section['markdown'], 1 ) ),
								pr_markup( pr_split( $section['markdown'], 2 ) ),
							);
							break;
						default:
							$data[$key]['content'] = pr_markup( $section['markdown'] );
							$data[$key]['style'] = SECTION_BLOCK_100;
					}
				}
				return $data;
			else:
				return pr_sanitize( $raw );
			endif;
		endif;
		return $raw;
	}

	// Return the stored key token
	function authToken() {
		return $this->_keyToken;
	}

	// Returns TRUE if the user is logged in
	function authorized() {
		$cookie = array_key_exists( 'pr_auth_token', $_COOKIE ) ? $_COOKIE['pr_auth_token'] : null;
		if ( $cookie == null ) return false; // no cookie so not auth'd
		return $cookie === sha1( $this->cryptSalt . '--' . $this->hashedPassword );
	}

	// Attempts to verify the given password and returns TRUE if valid
	function authorize($password) {
		if ( $this->saltHash( $password ) === $this->hashedPassword ):
			// Password verified so create a auth token for later
			$this->_keyToken = sha1( $this->cryptSalt . '--' . $this->saltHash( $password ) );
			return true;
		endif;
		return false;
	}

	// Return the active theme class name
	function activateTheme() {
		$themeId = $this->config('theme');
		if ( ! array_key_exists( $themeId, $GLOBALS['_PR_THEMES'] ) )
			$themeId = DEFAULT_THEME;
		return $GLOBALS['_PR_THEMES'][$themeId];
	}
}

// Create an instance of the PRData class
$prData = new PRData(DATA_STORE, SALTING_KEY);


/************* DOWNLOAD MODE *************/
if ( $prQ === 'download' ):
	if ( ! $prData->ready() )
		die( "ERROR: Could not load data file to build download" );
	$pdf = $prData->config( 'downloadable_pdf' );
	if ( $pdf == PDF_NONE )
		die( "ERROR: Downloading PDF of this resume is disabled" );
	// Build PDF based on the PRINT_URL and the mechanism chosen
	ob_clean();
	header( 'Pragma: public' );
	header( 'Cache-Control: private, max-age=0, must-revalidate' );
	header( 'Content-Type: application/pdf' );
	header( sprintf( 'Content-Disposition: inline; filename="%s.pdf"', 
		$prData->resume( 'title' ) ) );
	$fn = tempnam( sys_get_temp_dir(), 'PR' );
	switch( $pdf ) {
		case PDF_PRINCE:
			$sh = shell_exec( sprintf( '%s %s -o %s', PRINCE_EXEC, PRINT_URL, $fn ) );
			break;
		case PDF_WKHTML:
			$sh = shell_exec( sprintf( '%s %s %s', WKHTML_EXEC, PRINT_URL, $fn ) );
			break;
		default:
			die( "ERROR: Unknown PDF download mechanism specified" );
	}

	// The temp file at $fn will now be the PDF to send to browser
	$pdfcontent = file_get_contents( $fn );
	header( 'Content-Length: ' . strlen( $pdfcontent ) );
	echo $pdfcontent;
	unlink( $fn );
	exit( 0 ); // end the script
endif;


?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>TITLE_PLACEHOLDER</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="generator" content="PHP Resume (PR) by Andrew Bevitt">
	<meta name="description" content="<?php echo $prData->resume('description'); ?>">
	<meta name="author" content="<?php echo $prData->config('owner_name'); ?>">
	<?php if ( $prData->config('include_google_plus_author') )
		printf('<link rel="author" href="%s"/>', $prData->config('google_plus_author')); ?>

	<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.no-icons.min.css" rel="stylesheet">
	<link href="//netdna.bootstrapcdn.com/font-awesome/3.1.1/css/font-awesome.min.css" rel="stylesheet">
	<!--[if lt IE 9]>
		<script src="//cdnjs.cloudflare.com/ajax/libs/html5shiv/3.6.2/html5shiv.js"></script>
	<![endif]-->
	<!--[if lt IE 8]>
		<link href="//netdna.bootstrapcdn.com/font-awesome/3.1.1/css/font-awesome-ie7.min.css" rel="stylesheet">
	<![endif]-->
	<style type="text/css">
		html, body { height:100%; }
		#prWrap { min-height: 100%; height: auto !important; height: 100%; margin: 0 auto -30px; }
		#prWrap .filler { height: 40px; }
		#prPower { height:30px;clear:both; }
		.prcontent legend { margin-bottom:0; }
		.pr-section-row { border-top: 2px dashed #999; margin-top: 1em; padding-top:1em; }
		.pr-print-only { display: none; }
		.prfpasswd {
			max-width: 250px;
			padding: 19px 29px 29px;
			margin: 20px auto 20px;
			background-color: #fff;
			border: 1px solid #e5e5e5;
			-webkit-border-radius: 5px;
			-moz-border-radius: 5px;
			border-radius: 5px;
			-webkit-box-shadow: 0 1px 2px rgba(0,0,0,.05);
			-moz-box-shadow: 0 1px 2px rgba(0,0,0,.05);
			box-shadow: 0 1px 2px rgba(0,0,0,.05);
		}
		.prfpasswd input[type="password"] {
			font-size: 16px;
			height: auto;
			margin-bottom: 15px;
			padding: 7px 9px;
		}
		.prfpasswd .alert { text-align:center; }

		/* Large desktop */
		@media (min-width: 1200px) { }
		
		/* Portrait tablet to landscape and desktop */
		@media (min-width: 768px) and (max-width: 979px) { }
		
		/* Landscape phone to portrait tablet */
		@media (max-width: 767px) {
			#prPower { margin-left:-20px; margin-right:-20px; padding-left:20px; padding-right:20px; }
		}

		/* Landscape phones and down */
		@media (max-width: 480px) { }

		/* Styles for the theme */
		STYLE_THEME
	</style>
	<style type="text/css" media="print">
		/* Styles for printing the theme */
		STYLE_PRINT
	</style>
</head>
<?php

// Output an opening body tag depending on printing mode
printf( "<body%s>\n", 
	$prQ === 'print' ? ' class="pr-printed"' : '' );

// Global variable for holding page specific JS
$prJS = '';


/************* INSTALL MODE *************/
if ( $prQ === 'install' ):

	// If the first already exists then we can't reinstall
	if ( $prData->dataStoreExists() )
		die("PR is already installed");

	// Some basic HTML markup describing install process
	printf("<h1 class=\"text-center\">PR Installer</h1>\n");

	// If the initial password set form was posted do the install
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['pr_password1'] ) &&
			isset( $_POST['pr_password2'] ) && $_POST['pr_password1'] === $_POST['pr_password2'] ):
		// Create the data file
		if ( ! $prData->createDataStore( $_POST['pr_password1'] ) ): ?>
<div class="alert alert-error">
	<strong>ERROR:</strong> Could not create the data store file <code><?php echo DATA_STORE; ?></code>
</div><?php
		else: ?>
<div class="alert alert-success">
	<strong>Great news:</strong> The data store file <code<?php echo DATA_STORE; ?></code> was created.
</div><?php
		endif;

		// Now try the .htaccess file
		if ( ! ( is_file( '.htaccess') || touch( '.htaccess' ) ) ): ?>
<div class="alert alert-warning">
	<strong>WARNING:</strong> The installer could not create a <code>.htaccess</code> file.
</div><?php
		else: 
			// Make sure it can be written to and then write it
			// TODO: Only write the rules once - i.e. wrap in placemarkers to check
			$wbytes = false;
			if ( is_writable( '.htaccess' ) )
				$wbytes = file_put_contents( '.htaccess', $htaccess, FILE_APPEND|LOCK_EX );
			if ( $wbytes === false ): ?>
<div class="alert alert-info">
	<strong>Note:</strong> The <code>.htaccess</code> file could not be updated please add the following:
	<pre><?php echo htmlspecialchars( $htaccess ); ?></pre>
</div><?php
			else: // .htaccess updated ?>
<div class="alert alert-success">
	<strong>Even better:</strong> The <code>.htaccess</code> file has been successfully configured.
</div><?php
			endif;
		endif; // .htaccess
	
	else: // render a form to request a password

		// did we get passwords that didn't match?
		$nonMatch = isset( $_POST['pr_password1'] ) && isset( $_POST['pr_password2'] ) && 
			$_POST['pr_password1'] !== $_POST['pr_password2'];
?>
<div id="prWrap">
<div class="container">
	<form method="post" class="prfpasswd" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php if ( $nonMatch ): ?><div class="alert alert-error">Passwords do not match</div><?php endif; ?>
		<input name="pr_password1" type="password" class="input-block-level" placeholder="Password">
		<input name="pr_password2" type="password" class="input-block-level" placeholder="Confirm password">
		<button class="btn btn-block btn-large btn-primary" type="submit">Install</button>
	</form>
</div>
</div>
<?php
	endif; // installer GET / POST 
?>
<div id="prPower">
<div class="container">
	<p class="muted text-center">Created with <abbr title="PHP Resume">PR</abbr> by <a href="http://andrewbevitt.com/code/pr/">Andrew Bevitt</a></p>
</div>
</div>
<?php

else: // not installing so check data file

	// If the data file doesn't exist here then can't continue
	if ( ! $prData->ready() )
		die("ERROR: Failed to load PR data file");

endif;


/************* LOAD THEME *************/
$prTheme = $prData->activateTheme();


/************* EDIT MODE *************/
if ( $prQ === 'edit' ):
	// Has the logout action been invoked?
	if ( isset( $_GET['a'] ) && $_GET['a'] === 'logout' ):
		ob_clean();
		setcookie( 'pr_auth_token', '', time()-3600 );
		header("Location: " . READ_URL, true, 302);
		printf('<html><body><p>Redirecting to <a href="%1$s">%1$s</a></p></body></html>',
			READ_URL);
		exit(0);
	endif;

	// Did we receive a login POST?
	$loginFailure = false;
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['password'] ) ):
		if ( $prData->authorize( $_POST['password'] ) ):
			// successful login so store cookie and redirect to GET
			ob_clean();
			setcookie( 'pr_auth_token', $prData->authToken() );
			$http = isset( $_SERVER['HTTPS'] ) && strlen( $_SERVER['HTTPS'] ) > 0 ? 'https://' : 'http://';
			$host = $_SERVER['HTTP_HOST'];
			$uri = $_SERVER['REQUEST_URI'];
			header("Location: $http$host$uri", true, 302);
			printf('<html><body><p>Redirecting to <a href="%s">%s</a></p></body></html>',
				$http.$host.$uri, $uri);
			exit(0);
		endif;

		// If we reach here the login failed
		$loginFailure = true;
	endif;

	// Do we need to login?
	if ( ! $prData->authorized() ):
?>
<div id="prWrap">
<div class="container">
	<form method="post" class="prfpasswd" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php if ( $loginFailure ): ?><div class="alert alert-error">Password Incorrect</div><?php endif; ?>
		<input name="password" type="password" class="input-block-level" placeholder="Password">
		<button class="btn btn-block btn-large btn-primary" type="submit">Authenticate</button>
	</form>
</div>
</div>
<?php
	else: // login not required

		// Should content or config be the active screen
		$message = null;
		$contentActive = true;
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( 
				isset( $_POST['pr_config'] ) || isset( $_POST['pr_changepwd'] ) ) )
			$contentActive = false;

		// Was the content update invoked?
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['pr_content'] )):
			$saveres = $prData->saveContent( $_POST );
			if ( $saveres === true ):
				$message = 'Content updated successfully';
				$messageClass = 'success';
			else:
				$message = 'Content could not be saved: ' . $saveres;
				$messageClass = 'error';
			endif;
		endif;

		// Was the config option update invoked?
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['pr_config'] ) ):
			if ( $prData->saveOptions( $_POST ) ):
				$message = 'Options updated successfully';
				$messageClass = 'success';
			else:
				$message = 'Some options could not be saved - please correct errors below';
				$messageClass = 'error';
			endif;
		endif;

		// Was the password change invoked?
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['pr_changepwd'] ) ):
			if ( isset( $_POST['pr_password1'] ) && isset( $_POST['pr_password2'] ) &&
					$_POST['pr_password1'] !== $_POST['pr_password2'] ):
				$message = 'Password not changed - passwords did not match';
				$messageClass = 'error';
			else:
				// password changed so update and save config
				if ( $prData->newPassword( $_POST['pr_password1'] ) ):
					$message = 'Password updated successfully';
					$messageClass = 'info';
				else:
					$message = 'Failed to change password - unknown error';
					$messageClass = 'error';
				endif;
			endif;
		endif;

?>
<div id="prWrap">
<div class="container">
<h1>PR Edit Mode</h1>
<?php
	if ( $message !== null && strlen( $message ) > 0 ):
?>
<div class="alert alert-<?php echo $messageClass; ?>">
	<strong><?php echo $message; ?></strong>
</div>
<?php
	endif;
?>
<div class="tabbable">
	<ul class="nav nav-tabs">
		<li<?php if ( $contentActive ) printf(' class="active"'); ?>><a href="#prEditContent" data-toggle="tab">Content</a></li>
		<li<?php if ( ! $contentActive ) printf(' class="active"'); ?>><a href="#prEditConfiguration" data-toggle="tab">Configuration</a></li>
		<li><a href="#prEditHelp" data-toggle="tab">Help</a></li>
		<li><a href="?a=logout">Logout</a></li>
	</ul>
	<div class="tab-content">
		<div class="tab-pane<?php if ( $contentActive ) printf(' active'); ?>" id="prEditContent">
			<!-- Form for resume content -->
			<h3>Resume Content</h3>
			<form method="post" class="form-horizontal prcontent" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
				<input name="pr_content" type="hidden" value="content">
				<fieldset>
					<legend>Details</legend>
					<div class="control-group">
						<label for="resume_title" class="control-label">Title</label>
						<div class="controls">
							<input id="resume_title" name="resume_title" type="text" class="span6" value="<?php echo $prData->resume('title', false); ?>" required>
							<span class="help-block">What should appear in the <code>&lt;title&gt;&lt;/title&gt;</code> tag?</span>
						</div>
					</div>
					<div class="control-group">
						<label for="resume_description" class="control-label">Meta description</label>
						<div class="controls">
							<textarea id="resume_description" name="resume_description" class="span6" required><?php echo $prData->resume('description', false); ?></textarea>
							<span class="help-block">The meta description field for the headers</span>
						</div>
					</div>
					<div class="control-group">
						<label for="resume_tagline" class="control-label">Tagline</label>
						<div class="controls">
							<input id="resume_tagline" name="resume_tagline" type="text" class="span6" value="<?php echo $prData->resume('tagline', false); ?>">
							<span class="help-block">Tagline or professional label to display alongside/under your name<br/>Leave blank if you don't want this shown.</span>
						</div>
					</div>
					<div class="control-group">
						<label for="resume_objective_profile" class="control-label">Objective / Profile</label>
						<div class="controls">
							<textarea id="resume_objective_profile" name="resume_objective_profile" class="span6" required><?php echo $prData->resume('objective_profile', false); ?></textarea>
							<span class="help-block">A short description of yourself and your professional objectives / direction / profile.<br/>You can add formatting with Markdown syntax.</span>
						</div>
					</div>
				</fieldset>
				<div class="control-group">
					<div class="controls">
						<button class="btn btn-primary" type="submit">Save Changes</button>
						<a href="<?php echo $_SERVER['REQUEST_URI']; ?>" title="Clear changes" class="btn btn-warning">Clear changes</a>
					</div>
				</div>
				<fieldset id="sectionContainer">
					<legend>Sections</legend>
					<div class="control-group">
						<div class="controls">
							<button class="btn" type="button" id="addSection"><i class="icon-plus"></i> Section</button>
							<span class="help-inline">Sections will appear on your resume in the order specified below.</span>
						</div>
					</div>
<?php
	// Loop over the resume sections
	$counter = 0;
		$sortButtons = '<div class="btn-group"><button type="button" class="btn pr-section-up"><i class="icon-sort-up"></i></button><button type="button" class="btn pr-section-down"><i class="icon-sort-down"></i></button></div><div class="btn-group"><button type="button" class="btn btn-error pr-section-delete"><i class="icon-remove icon-white"></i></button></div>';
	foreach( $prData->resume('sections', false) as $key=>$data ) {
		if ( $key === '_new' ):
			// Use JS to create new entries in the fieldset
			ob_start();
			edit_section_row( 'NEWTPL', 'SORTED', $data['style'], $data['heading'], $data['markdown'] );
			$innerHTML = preg_replace( '/[\n\r\t]*/', '', ob_get_clean() );
			$prJS .= "
jQuery(document).ready(function($) {
	// Template HTML for a section
	var tplHtml = '$innerHTML';

	// Function called when moving position of section rows up
	function moveSectionUp(idx) {
		// is there a section before this one?
		var thisRow = $('#section-row-' + idx);
		var prevRow = $('#section-row-' + idx).prev('.pr-section-row');
		if (prevRow.size() > 0) {
			console.log('move up');
			// swap the order values 
			var myOrder = thisRow.find('input.section-order').first();
			var prevOrder = prevRow.find('input.section-order').first();
			var tOrder = myOrder.val();
			myOrder.val(prevOrder.val());
			prevOrder.val(tOrder);
			// now insert the previous row after this row
			prevRow.insertAfter(thisRow);
		} else {
			console.log('no prev .pr-section-row found so not moving');
		}
	}

	// Function called when moving position of section rows down
	function moveSectionDown(idx) {
		// is there a section after this one?
		var thisRow = $('#section-row-' + idx);
		var nextRow = $('#section-row-' + idx).next('.pr-section-row');
		if (nextRow.size() > 0) {
			console.log('move down');
			// swap the order values
			var myOrder = thisRow.find('input.section-order').first();
			var nextOrder = nextRow.find('input.section-order').first();
			var tOrder = myOrder.val();
			myOrder.val(nextOrder.val());
			nextOrder.val(tOrder);
			// now insert the next row before this row
			nextRow.insertBefore(thisRow);
		} else {
			console.log('no next .pr-section-row found so not moving');
		}
	}

	// Remove a section from the resume
	function removeSection(idx) {
		var thisRow = $('#section-row-' + idx);
		// Wasn't saved so simply remove it from the DOM
		thisRow.remove();
	}

	// Add buttons for sort ordering
	function hideOrderAddButtons(idx) {
		var block = $('#section-row-' + idx);
		var orders = block.children(':first').children('.controls');
		orders.children().hide();
		orders.prepend('$sortButtons');
		orders.find('button.pr-section-up').click(function(e){ moveSectionUp(idx); });
		orders.find('button.pr-section-down').click(function(e){ moveSectionDown(idx); });
		orders.find('button.pr-section-delete').click(function(e){ removeSection(idx); });
	}

	// Add new section elements to the HTML
	$('#addSection').click(function(e){
		e.preventDefault(); // don't process up the tree
		console.log('adding new section');
		var nextNewValue = parseInt($('#prCounter').val()) + 1;
		$('#prCounter').before(tplHtml.replace(/NEWTPL/g, 'EX_' + nextNewValue).replace('SORTED', nextNewValue));
		$('#prCounter').val(nextNewValue);
		hideOrderAddButtons('EX_' + nextNewValue);
		return false;
	});
});
";
		else: // an existing section
			$counter += 1;
			edit_section_row( 'EX_' . $counter, $counter, $data['style'], $data['heading'], $data['markdown'] );
		endif;
	}
	// Output a counter field
	printf( '<input type="hidden" id="prCounter" name="prCounter" value="%s">', $counter );
	// If there are existing records then enable sorting
	if ( $counter > 0 ):
		$prJS .= "
jQuery(document).ready(function($) {
	$('.pr-section-row').each(function() {
		var block = $(this);
		var orders = block.children(':first').children('.controls');
		orders.children().hide();
		orders.prepend('$sortButtons');
	});

	$('button.pr-section-up').click(function(e) {
		e.preventDefault();
		// is there a section before this one?
		var thisRow = $(this).parents('.pr-section-row');
		var prevRow = thisRow.prev('.pr-section-row');
		if (prevRow.size() > 0) {
			console.log('move up');
			// swap the order values
			var myOrder = thisRow.find('input.section-order').first();
			var prevOrder = prevRow.find('input.section-order').first();
			var tOrder = myOrder.val();
			myOrder.val(prevOrder.val());
			prevOrder.val(tOrder);
			// now insert the previous row after this row
			prevRow.insertAfter(thisRow);
		} else { 
			console.log('no previous .pr-section-row found so not moving');
		}
		return false;
	});

	$('button.pr-section-down').click(function(e) {
		e.preventDefault();
		// is there a section after this one?
		var thisRow = $(this).parents('.pr-section-row');
		var nextRow = thisRow.next('.pr-section-row');
		if (nextRow.size() > 0) {
			console.log('move down');
			// swap the order values
			var myOrder = thisRow.find('input.section-order').first();
			var nextOrder = nextRow.find('input.section-order').first();
			var tOrder = myOrder.val();
			myOrder.val(nextOrder.val());
			nextOrder.val(tOrder);
			// now insert the next row before this row
			nextRow.insertBefore(thisRow);
		} else {
			console.log('no next .pr-section-row found so not moving');
		}
		return false;
	});

	$('button.pr-section-delete').click(function(e) {
		e.preventDefault();
		var thisRow = $(this).parents('.pr-section-row');
		if ( ! thisRow.find('input.section-delete').prop('checked') ) {
			thisRow.find('input.section-delete').prop('checked', true);
			$(this).prop('disabled', true);
			thisRow.prepend('<div class=\"alert alert-info\">Section will be deleted when resume is saved.</div>');
		}
	});
});
";
	endif;
?>
				</fieldset>
				<div class="control-group">
					<div class="controls">
				<button class="btn btn-primary" type="submit">Save Changes</button>
				<a href="<?php echo $_SERVER['REQUEST_URI']; ?>" title="Clear changes" class="btn btn-warning">Clear changes</a>
					</div>
				</div>
			</form>
		</div>
		<div class="tab-pane<?php if ( ! $contentActive ) printf(' active'); ?>" id="prEditConfiguration">
			<!-- Options control panel form -->
			<h3>Options</h3>
			<form method="post" class="form-horizontal" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
				<input name="pr_config" type="hidden" value="config">
<?php
	// Render each form option
	$prData->renderOptions( false );
?>
				<div class="control-group">
					<div class="controls">
				<button class="btn btn-large btn-primary" type="submit">Save Changes</button>
					</div>
				</div>
			</form>

			<!-- Change Password Form -->
			<h3>Change Password</h3>
			<form method="post" class="form-horizontal" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
				<input name="pr_changepwd" type="hidden" value="do_it">
				<div class="control-group">
					<label class="control-label" for="pr_password1">New password</label>
					<div class="controls">
				<input name="pr_password1" type="password" placeholder="Password">
					</div>
				</div>
				<div class="control-group">
					<label class="control-label" for="pr_password2">Confirm</label>
					<div class="controls">
				<input name="pr_password2" type="password" placeholder="Confirm">
					</div>
				</div>
				<div class="control-group">
					<div class="controls">
				<button class="btn btn-primary" type="submit">Change Password</button>
					</div>
				</div>
			</form>
		</div>
		<div class="tab-pane" id="prEditHelp">
			<p>Help</p>
		</div>
	</div>
</div>
</div>
<div class="filler"></div>
</div>
<?php
	endif; // login required
?>
<div id="prPower">
<div class="container">
	<p class="muted text-center">Created with <abbr title="PHP Resume">PR</abbr> by <a href="http://andrewbevitt.com/code/pr/">Andrew Bevitt</a></p>
</div>
</div>
<?php
endif;


// Read mode does not need to set cookies or headers so flush buffer
$buffer = ob_get_clean();

// Replace the placeholders with appropriate text
$buffer = str_replace( 'TITLE_PLACEHOLDER', $prData->resume('title'), $buffer );
$buffer = str_replace( 'STYLE_THEME', $prTheme::stylesheet( $prData, true ), $buffer );
$buffer = str_replace( 'STYLE_PRINT', $prTheme::printsheet( $prData, true ), $buffer );
echo $buffer;


/************* CONTACT FORM *************/
$contactSuccess = true;
$contactError = null;
if ( $prQ === 'contact' ):

	// Decide if the form was submitted and valid if so process
	// if not then fall through to read mode with an error 
	// Step 1: Must have been posted to contact
	if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ):
		ob_clean();
		header("Location: " . READ_URL, true, 302);
		printf('<html><body><p>Redirecting to <a href="%1$s">%1$s</a></p></body></html>',
			READ_URL);
		exit(0);
	endif;
	// Step 2: Extract the form fields
	$yourName = $yourContact = $msgSubject = $whereFrom = $msgContent = null;
	foreach( array( 'name'=>'yourName', 'contact'=>'yourContact', 'subject'=>'msgSubject',
			'where'=>'whereFrom', 'message'=>'msgContent' ) as $formKey=>$variable ) {
		if ( ! empty( $_POST['prconf_'.$formKey] ) )
			$$variable = $_POST['prconf_'.$formKey];
	}
	// Step 3: Validate the form fields
	if ( is_null( $yourName ) || is_null( $yourContact ) || is_null( $msgSubject ) || is_null( $msgContent ) ) {
		$contactSuccess = false;
		$contactError = 'Please complete all required fields in the contact form';
	}

	// If validated then try to send email
	if ( $contactSuccess ):
		$msg = sprintf( "Hi %s,\r\n\r\nThis is a message from the contact form on your resume\r\n  %s\r\n\r\n" .
			"Message from: %s %s\r\nContact details: %s\r\nSubject: %s\r\n%s\r\n\r\n\r\nThanks for using PR!\r\n",
			$prData->config( 'owner_name' ), READ_URL,
			$yourName, is_null( $whereFrom ) ? '' : '(at ' . $whereFrom . ')',
			$yourContact, $msgSubject, $msgContent );
		$result = mail( $prData->config( 'owner_email' ), '[PR CONTACT]: '.$msgSubject, $msg,
			'From: ' . EMAIL_FROM . "\r\n" .
			'X-Mailer: PR-PHP/' . phpversion() );
		if ( ! $result ):
			$contactSuccess = false;
			$contactError = 'Your message was not accepted by the mail server';
		endif;
	endif;


	// If the message was accepted for sending then render complete
	if ( $contactSuccess ):
?>
<div id="prWrap">
<div class="container<?php if ( $prTheme::fluidTheme ) echo '-fluid'; ?>">
<?php

		// Call the theme renderer to get the contact message
		$prTheme::contacted( $prData, false );

?>
</div>
<div class="filler"></div>
</div>
<?php

		// End with a footer if configuration allows
		if ( $prData->config('pr_footer') ):
?>
<div id="prPower" class="pr-not-on-print">
	<div class="container">
		<p class="muted text-center">Created with <abbr title="PHP Resume">PR</abbr></p>
	</div>
</div>
<?php
		endif;
	else: // mail wasn't sent so need to fall into read mode
		$prQ = 'read';
	endif; // contactSuccess
endif; // contact mode


/************* READ MODE *************/
if ( $prQ === 'read' or $prQ === 'print' ):
?>
<div id="prWrap">
<div class="container<?php if ( $prTheme::fluidTheme ) echo '-fluid'; ?>">
<?php

	// Call the theme renderer to get the contents and
	$prTheme::render( $prData, false, $contactSuccess, $contactError ); // output direct

?>
</div>
<div class="filler"></div>
</div>
<?php

	// End with a footer if configuration allows
	if ( $prData->config('pr_footer') ):
?>
<div id="prPower" class="pr-not-on-print">
	<div class="container">
		<p class="muted text-center">Created with <abbr title="PHP Resume">PR</abbr></p>
	</div>
</div>
<?php
	endif;

endif; // read mode

// Only need scripts if not in print mode
if ( preg_match( '/^(read|edit|install)$/', $prQ ) ):
?>
<script src="//ajax.googleapis.com/ajax/libs/jquery/2.0.0/jquery.min.js"></script>
<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>
<script type="text/javascript">
//<![CDATA[
<?php echo $prJS; ?>
//]]>
</script>
<!-- Created with PR [http://andrewbevitt.com/code/pr/] by <?php echo $prData->config('owner_name'); ?> -->
<?php
endif;

// If in PRINT mode activate printing via JS??
if ( $prQ === 'print' ):
?>
<script type="text/javascript">
//<![CDATA[
setTimeout( function(){ window.print(); }, 750 );
//]]>
</script>
<?php
endif; // print trigger
?>
</body>
</html>
