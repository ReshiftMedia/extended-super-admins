<?php
/**
 * Retrieve and use Codex information about capabilities
 * @package WordPress
 * @subpackge ExtendedSuperAdmins
 * @since 0.4a
 * @todo Improve the way parsed Wiki info is stored
 * @todo Improve the way timestamps are checked - hopefully find a way to use the revision date in the Codex to compare against the retrieval timestamp
 */

/**
 * A function to retrieve the Roles and Capabilities page from the WordPress Codex
 * 
 * Relies on the MediaWiki API. If the Codex information has been retrieved in the last 7 days,
 * we return the information as we have it stored in the local database. If not, we add that
 * information to the local database and return it directly from the Codex.
 *
 * @since 0.4a
 * @uses WP_Http::request()
 * @uses maybe_unserialize
 * @uses get_site_option()
 * @uses add_site_option()
 * @return string the raw Wiki information from the Codex
 */
function getCodexCapabilities() {
	if( ( $capsInfo = get_site_option( '_esa_capsCodexInfo', false, true ) ) !== false ) {
		if( $capsInfo['time_retrieved'] >= ( time() - ( 7 * 24 * 60 * 60 ) ) ) {
			return $capsInfo['pageContent'];
		}
	}
	if( !class_exists( 'WP_Http' ) )
		require_once( ABSPATH . '/wp-includes/class-http.php' );
	
	$capsInfo = new WP_Http();
	$capsInfo = maybe_unserialize( $capsInfo->request( 'http://codex.wordpress.org/api.php?action=query&prop=revisions&meta=siteinfo&titles=Roles_and_Capabilities&rvprop=content|timestamp&format=php' ) );
	if( $capsInfo['response']['code'] != 200 ) {
		if( ( $capsInfo = get_site_option( '_esa_capsCodexInfo', false, true ) ) !== false )
			return $capsInfo['pageContent'];
		else
			return false;
	}
	$tmp = maybe_unserialize( $capsInfo['body'] );
	$tmp = maybe_unserialize( $tmp['query'] );
	$tmp = maybe_unserialize( $tmp['pages'] );
	$tmp = array_shift( $tmp );
	$tmp = $tmp['revisions'][0];
	$capsInfo = array( 'time_retrieved' => time(), 'revision_time' => strtotime( $tmp['timestamp'] ), 'pageContent' => $tmp['*'] );
	add_site_option( '_esa_capsCodexInfo', $capsInfo );
	return $capsInfo['pageContent'];
}

/**
 * Locates the specific capability information
 *
 * Parses the Roles and Capabilities information that was retrieved from the codex, and 
 * finds the specific capability to be returned.
 * Returns a semi-parsed version of the information
 * @since 0.4a
 * @param string $cap the name of the cap to look for
 * @param array $caps_descriptions an array containing the items that have already been located
 * @param bool $include_titles whether or not to include the heading in the returned string
 * @uses parseWiki()
 * @uses getCodexCapabilities()
 * @return string the semi-parsed Wiki information from the Codex
 */
function findCap( $cap, $caps_descriptions=array(), $include_titles=false ) {
	if( array_key_exists( $cap, $caps_descriptions ) )
		return $caps_descriptions[$cap];
	
	$capsPage = getCodexCapabilities();
	if( !strstr( $capsPage, '===' . $cap . '===' ) )
		return false;
	
	$startPos = strpos( $capsPage, '===' . $cap . '===' );
	$endPos = strpos( $capsPage, '==', ( $startPos + strlen( '===' . $cap . '===' ) ) );
	$capsInfo = substr( $capsPage, ( $startPos + strlen( '===' . $cap . '===' ) ), ( $endPos - ( $startPos + strlen( '===' . $cap . '===' ) ) ) );
	return '' . ( ( $include_titles ) ? '<h3>' . $cap . '</h3>' : '' ) . parseWiki( $capsInfo );
}

/**
 * Parse the Wiki formatting of information from the Codex
 *
 * Currently returns semi-parsed information. Parses the level 3 headers
 * and local links from the Wiki information.
 * @since 0.4a
 * @param string $content the content to be parsed
 * @todo Parse ordered and unordered lists
 * @todo Parse external links
 * @uses wpautop()
 * @return string the semi-parsed information
 */
function parseWiki( $content ) {
	/* If it's already been parsed, or there is nothing to parse, we return the content */
	if( empty($content) || ( stristr( $content, '<p>' ) && !strstr( $content, '[' ) && !strstr( $content, '\'\'\'' ) ) )
		return $content;
	
	/* Parse the content in long-hand (meaning that each function call is on its own line */
	$content = wpautop( $content );
	$content = preg_replace( '/\[\[\#([^\]]+?)\|([^\]]+?)\]\]/', '<a href="http://codex.wordpress.org/Roles_and_Capabilities#$1">$2</a>', $content );
	$content = preg_replace( '/\[\[([^\]]+?)\|([^\]]+?)\]\]/', '<a href="http://codex.wordpress.org/$1" target="_codex_window">$2</a>', $content );
	$content = preg_replace( '/\[([^(\[|\s|\])]+?)\s([^(\[|\s|\])]+?)\]/', '<a href="$1">$2</a>', $content );
	$content = preg_replace( '#\'\'\'([^\']+?)\'\'\'#', '<strong>$1</strong>', $content );
	return $content;
	
	/* Parse the content using nested functions rather than one-at-a-time
	   If we found, for some reason, that the code above was really slow, 
	   we'd try it this way, instead; but I suspect this is actually slower.
	   We normally would never get this far, unless we comment out the lines 
	   above. */
	return preg_replace( '#\'\'\'([^\']+?)\'\'\'#', '<strong>$1</strong>', preg_replace( '/\[([^(\[|\s|\])]+?)\s([^(\[|\s|\])]+?)\]/', '<a href="$1">$2</a>', preg_replace( '/\[\[([^\]]+?)\|([^\]]+?)\]\]/', '<a href="http://codex.wordpress.org/$1" target="_codex_window">$2</a>', preg_replace( '/\[\[\#([^\]]+?)\|([^\]]+?)\]\]/', '<a href="http://codex.wordpress.org/Roles_and_Capabilities#$1">$2</a>', wpautop( $content ) ) ) ) );
	
	/* Try using the Codex API to parse the content. Because this has to make an HTTP
	   request every time, this is really just here for future reference. This code
	   should never be used as-is, which is why we're returning the content before we
	   get here. */
	$capsInfo = new WP_Http;
	$tmp = $capsInfo->request( 'http://codex.wordpress.org/api.php?action=parse&format=php&text=' . urlencode($content) );
	if( $tmp['response']['code'] == 200 ) {
		$tmp = maybe_unserialize( $tmp['body'] );
		$tmp = maybe_unserialize( $tmp['parse'] );
		$tmp = maybe_unserialize( $tmp['text'] );
		$tmp = $tmp['*'];
		return html_entity_decode( $tmp );
	}
}
?>