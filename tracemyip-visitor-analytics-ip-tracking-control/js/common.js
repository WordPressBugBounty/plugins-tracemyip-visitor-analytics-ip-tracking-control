
function tmip_select_all(id) {
	document.getElementById(id).focus();
	document.getElementById(id).select();
}
( function( $ ) {
    $( document ).ready( function() {
		
setTimeout(function() {
	// 🛠️ 🚀 🏆 ✨
    var items = [
        { 
            href: 'admin.php?page=tmip_admpanel_menu', 
            icon: '', 
            position: 1, 
            textClass: 'tmip_wp_menu_repo_link', 
            matchClass: 'wp-first-item'
        },
        { 
            href: 'admin.php?page=tmip_rate_serv_lnk', 
            icon: '🏆', 
            position: 1, 
            textClass: 'tmip_wp_menu_rate_link', 
            matchClass: ''
        },
        { 
            href: 'admin.php?page=tmip_lnk_wp_settings', 
            icon: '', 
            position: 1, 
            textClass: 'tmip_wp_menu_sett_link', 
            matchClass: ''
        },
        { 
            href: 'admin.php?page=tmip_upgrade_page', 
            icon: '', 
            position: 1, 
            textClass: 'placeholder', 
            matchClass: ''
        }
    ];

    items.forEach(function(item) {
        var selector = '#adminmenu a[href="' + item.href + '"]';
        
        if (item.matchClass) {
            selector += '.' + item.matchClass;
        }

        var link = document.querySelector(selector);
        if (link) {
            // Create and style the span for the icon
            var icon = document.createElement('span');
            icon.classList.add('tmip_wp_cmenu_icon');
            icon.textContent = item.icon;
            var textSpan = document.createElement('span');
            textSpan.classList.add(item.textClass);
            textSpan.textContent = link.textContent.trim();
            link.textContent = '';

            if (item.position === 1) {
                link.appendChild(textSpan); // Add text span first
                link.appendChild(icon); // Add icon after text span
            } else if (item.position === 2) {
                link.appendChild(icon); // Add icon first
                link.appendChild(textSpan); // Add text span after icon
            }
        }
    });
}, 10);

        $( '.rate-tmip-stars' ).find('a').hover(
            function() {
                $(this).nextAll('a').children( 'span' ).removeClass('dashicons-star-filled').addClass( 'dashicons-star-empty' );
                $(this).prevAll('a').children( 'span' ).removeClass( 'dashicons-star-empty' ).addClass('dashicons-star-filled');
                $(this).children( 'span' ).removeClass( 'dashicons-star-empty' ).addClass('dashicons-star-filled');
            }, function() {
                var rating = $( 'input#rating' ).val();
                if ( rating ) {
                    var list = $( '.rate-tmip-stars a' );
                    list.children( 'span' ).removeClass('dashicons-star-filled').addClass( 'dashicons-star-empty' );
                    list.slice( 0, rating ).children( 'span' ).removeClass( 'dashicons-star-empty' ).addClass('dashicons-star-filled');
                }
            }
        );
    });

})( jQuery );

