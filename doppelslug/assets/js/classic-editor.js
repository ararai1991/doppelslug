/**
 * Doppelslug for the Classic Editor: fills the "Lookalike URLs" box and refreshes it
 * whenever WordPress updates the permalink under the title. The box is only shown
 * while there are lookalikes to warn about.
 *
 * Everything from the server is inserted as text, never as HTML.
 */
( function ( $, wp ) {
	'use strict';

	var __ = wp.i18n.__;
	var $postbox;
	var $box;
	var latest = 0;

	/**
	 * The slug WordPress shows under the title, falling back to the Slug box.
	 *
	 * @return {string} Slug, possibly empty.
	 */
	function currentSlug() {
		return $.trim( $( '#editable-post-name-full' ).text() || $( '#post_name' ).val() || '' );
	}

	/**
	 * Appends an element containing plain text.
	 *
	 * @param {jQuery} $parent   Container.
	 * @param {string} tag       Tag name.
	 * @param {string} text      Text content.
	 * @param {string} className Optional class.
	 * @return {jQuery} The new element.
	 */
	function addText( $parent, tag, text, className ) {
		var $node = $( document.createElement( tag ) ).text( text );

		if ( className ) {
			$node.addClass( className );
		}

		return $node.appendTo( $parent );
	}

	/**
	 * Appends a link that opens in a new tab. Only http(s) URLs are linked.
	 *
	 * @param {jQuery} $parent Container.
	 * @param {string} url     URL.
	 * @param {string} text    Link text.
	 */
	function addLink( $parent, url, text ) {
		if ( ! /^https?:\/\//i.test( url ) ) {
			$parent.text( text );
			return;
		}

		$( document.createElement( 'a' ) )
			.attr( { href: url, target: '_blank', rel: 'noopener noreferrer' } )
			.text( text )
			.appendTo( $parent );
	}

	/**
	 * Draws a report in the box.
	 *
	 * @param {Object} report Report from the REST endpoint.
	 */
	function render( report ) {
		$box.empty();
		$postbox.toggleClass( 'doppelslug-quiet', 'conflicts' !== report.status );

		if ( 'conflicts' !== report.status ) {
			return;
		}

		addText( $box, 'p', report.summary, 'doppelslug-summary' );

		( report.groups || [] ).forEach( function ( group ) {
			var $group = $( '<div class="doppelslug-group"></div>' ).appendTo( $box );
			var $list = $( '<ul></ul>' );

			addText( $group, 'p', group.message );
			group.posts.forEach( function ( item ) {
				addLink( $( '<li></li>' ).appendTo( $list ), item.link, item.title );
			} );
			$list.appendTo( $group );

			if ( group.now ) {
				addText( $group, 'p', group.now, 'doppelslug-note' );
			}
		} );

		if ( report.tip ) {
			addText( $box, 'p', report.tip, 'doppelslug-note' );
		}

		if ( report.settingsUrl ) {
			addLink( $( '<p></p>' ).appendTo( $box ), report.settingsUrl, __( 'Change how WordPress handles these addresses', 'doppelslug' ) );
		}
	}

	/**
	 * Asks the server about the current slug and draws the answer.
	 */
	function check() {
		var postId = parseInt( $( '#post_ID' ).val(), 10 );
		var slug = currentSlug();
		var title = $( '#title' ).val() || '';
		var request;

		if ( ! postId || ( ! slug && ! title ) ) {
			$postbox.addClass( 'doppelslug-quiet' );
			return;
		}

		request = ++latest;

		wp.apiFetch( {
			path: wp.url.addQueryArgs( '/doppelslug/v1/check', {
				post_id: postId,
				slug: slug,
				title: slug ? '' : title,
			} ),
		} )
			.then( function ( report ) {
				if ( request === latest ) {
					render( report );
				}
			} )
			.catch( function () {
				// Stay out of the way if the check fails; the editor works as usual.
				if ( request === latest ) {
					$postbox.addClass( 'doppelslug-quiet' );
				}
			} );
	}

	$( function () {
		$postbox = $( '#doppelslug' );
		$box = $postbox.find( '.doppelslug-box' );

		if ( ! $box.length ) {
			return;
		}

		check();

		// WordPress rebuilds the permalink line with this AJAX action after the title is
		// first entered and whenever the slug is edited.
		$( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
			if ( settings && 'string' === typeof settings.data && -1 !== settings.data.indexOf( 'action=sample-permalink' ) ) {
				check();
			}
		} );
	} );
}( jQuery, window.wp ) );
