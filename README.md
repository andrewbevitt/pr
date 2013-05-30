# PHP Resume (PR)

A one-script wonder for simple HTML resumes.

There is plenty of documentation in the `index.php` file.

This is MIT licensed so have fun :).

## Requirements

1. PHP 5 with the JSON PECL Extension
2. `.htaccess` or equivalent server configuration
3. Some knowledge Markdown

## Getting started

1. Copy `index.php` to a folder on your server
1. Edit `index.php` and change the `define()`s at the top as instructed
2. Point your browser to http://yourdomain.com/pr/index.php?q=install
3. Enter a password for your installation
4. Messages will confirm what you need to do
5. Once finished go to http://yourdomain.com/pr/edit/
6. Login and enter some content and change configuration options
7. Read your resume at http://yourdomain.com/pr/

### Caching

PR uses a single cookie `pr_auth_token` for keeping the session open whilst editing resume content. If your PR install is behind a caching proxy (e.g. Varnish Cache) then you will need to make sure the proxy doesn't drop the `pr_auth_token` cookie otherwise you won't be able to login. If you are using Varnish then here are some VCL rules that will help:

    # Pass request if has PR token cookie
    if (req.http.Cookie ~ "pr_auth_token") {
        return (pass);
    }
    # Unset cookies unless logging into WP or PR
    if (!(req.url ~ "wp-(login|admin)|resume\/edit")) {
        unset req.http.cookie;
    }
    # Unset response cookies unless for WP or PR admin
    if (!(req.url ~ "wp-(login|admin)|resume\/edit")) {
        unset beresp.http.set-cookie;
    }


## What does the `.htaccess` file do?

The `.htaccess` file is used by the Apache webserver to redirect all requests made for files in the same directory to the `index.php` script. If you're not using Apache you'll need to configure your other webserver to perform the same function. Here are the rules added to the `.htaccess` file:

    # BEGIN_PR
    <IfModule mod_rewrite.c>
		RewriteEngine On
		RewriteCond %{REQUEST_FILENAME} !index.php
		RewriteRule .* index.php?q=$0 [QSA,L]
	</IfModule>
	# END_PR

## Customisation

You can customise the PR reading layout by writing your own theme class.
Theme classes are free form but MUST conform to the same API as the
`PRTheme_Default` class which you can see in the code, essentially:

    class THEME_NAME {
        const themeName = 'PR Default Theme';
        const fluidTheme = false;
        public static function stylesheet( $data, $return );
        public static function printsheet( $data, $return );
        public static function contacted( $data, $return );
        public static function render( $data, $return,
            $contactSuccess, $contactError );
    }

Once your theme is ready add it to the `$_PR_THEMES` global array and it
will appear in the configuration screen. The theme should fit within
the Twitter Bootstrap `<div class="container"></div>` tags.

## Feedback
If you like this script, and hey it might help you get that dream job,
then I'd love it if you could either let me know you're using it. You
can contact me at http://andrewbevitt.com/contact/.

You can support my work by donating http://andrewbevitt.com/donations/.

