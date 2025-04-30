=== Export WP Page to Static HTML & PDF ===
Contributors: recorp  
Tags: export, static, html, pdf, performance
Requires at least: 4.1  
Tested up to: 6.8 
Stable tag: 4.0.0  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Export any WP page to responsive static HTML/CSS and print-ready PDF in just one click.

== Description ==
Export WP Page to Static HTML & PDF is the ultimate **page-exporter** for WordPress. Instantly convert any page or post into **lightning-fast static HTML/CSS** and a **print-ready PDF** with — all with one click or a simple shortcode.  
  
- **Blazing Performance**: Eliminate database queries by serving static HTML/CSS.  
- **Print-Ready PDF**: Generate customizable, responsive PDFs with headers, footers, watermarks, and page numbers.  
- **Shortcode Control**: Use `[generate_pdf_button]` to place a “Generate PDF” button anywhere—no coding required.  
- **Role-Based Access**: Show the "Generate PDF" button only to selected WP roles.   

Whether you need **offline backups**, **secure page delivery**, or **easy PDF handouts**, this plugin has you covered.

https://www.youtube.com/watch?v=VEDG-5saLzY

== Features ==
* **Static HTML/CSS Export** – One-click conversion of pages & posts to fully responsive HTML/CSS.  
* **PDF Generator** – Create print-ready PDFs with customizable templates.  
* **Shortcodes** – `[generate_pdf_button]` & `[export_html_button]` for flexible placement.  
* **Role-Based Controls** – Only selected roles see the "Generate PDF" buttons.  

== Installation ==
1. Upload the folder `export-wp-page-static-html-pdf` to `/wp-content/plugins/`.  
2. Activate **Export WP Page to Static HTML & PDF** from the **Plugins** screen.  
3. Go to **Settings → Static HTML & PDF Export** to configure:  
   - **User Roles**: Select which WordPress roles can see the export buttons.  
   - **PDF Template**: Customize headers, footers, watermarks, fonts, and page numbers.  
   - **Export Limits**: Set daily PDF export caps and notification settings.  
4. Place the export buttons:  
   - **Admin Bar**: Auto-injects “Generate PDF” and “Export HTML” into the admin bar for allowed users.  
   - **Shortcode**: Add `[generate_pdf_button]` or `[export_html_button]` in any post, page, or widget.



= More plugins you may like =
* [AI Content Writing Assistant (Content Writer, ChatGPT, Image Generator) All in One](https://wordpress.org/plugins/ai-content-writing-assistant/)
https://www.youtube.com/watch?v=HvOkfBs7qss
* [Different Menu in Different Pages](https://wordpress.org/plugins/different-menus-in-different-pages/)
* [Pipe ReCaptcha](https://wordpress.org/plugins/pipe-recaptcha/)
* [Divi MailChimp Extension](https://wordpress.org/plugins/recorp-divi-mailchimp-extension/?clk=wp)
* [Menu import & export pro](https://myrecorp.com/product/menu-import-and-export-pro/?r=export-html&clk=wp)

== Screenshots ==
1. Default settings page layout of the plugin.
2. PDF generation button in the admin bar of a post.


== Shortcodes ==
`[generate_pdf_button]`  
: Inserts a “Generate PDF” button. Visible only to allowed roles.  
`[export_html_button]`  
: Inserts an “Export HTML” button. Visible only to allowed roles.

== Frequently Asked Questions ==
= How do I control which users see the export buttons? =  
Go to **Settings → Static HTML & PDF Export** and select the user roles under **Role-Based Access**.  

= What happens when the daily limit is reached? =  
Admin or Users see a friendly popup informing them they have reached their export limit. The button is disabled until the next 24-hour window.  

= Will this work with page builders like Elementor or Divi? =  
Absolutely. Exports capture the fully rendered front-end output—page builder layouts included.  

== Changelog ==

= 4.0.1 - 30 April 2025=
* UPDATED: Some PDF making js codes to generate pdf file smoothly.
 
= 4.0.0 =
* New: PDF export feature with `[generate_pdf_button]` shortcode  
* New: Role-Based Access Control for export buttons  
* New: Daily PDF export limits (2/day) with notifications  
* New: Background/asynchronous export jobs with progress bar  
* FIXED: Table creating issue while plugin activate.

= 3.0.0 - 9 July 2024  =
* Fixed lots of tweaks.

= 2.2.3 - 1 July 2024  =
* Fixed safe redirection

= 2.2.2 - 16 March 2024  =
* Fixed assets naming issues.
* Added php zip extension not installed notice.

= 2.2.1 - 30 November 2023 =
* Added webp image extension. Now this extension images will export also.

= 2.2.0 - 28 November 2023 =
* Made compatible with php version 8.2.
* Added "User roles can access" settings.
* Fixed very little security issue.
* Made some polishing.
* Fixed post searching issue.
* Fixed some more minor issues.

= 2.1.8 - 31 July 2023 =
* Fixed some minor issues.

= 2.1.7 - 28 June 2023 =
* Added review notice with "having problem" button.
* Added "Successfully exported" toast notification.

= 2.1.6 - 28 May 2023 =
* Fixed main site address still appearing issue in everywhere.

= 2.1.5 - 21 May 2023 =
* Fixed pro version direct installing issue.

= 2.1.4 - 23 December 2022 =
* Fixed a minor issue.

= 2.1.3 - 1 November 2022 =
* Fixed a major issue.

= 2.1.2 - 1 November 2022 =
* Fixed a minor issue.

= 2.1.1 - 12 August 2022 =
* Fixed some major issues.

= 2.1.0 - 23 June 2022 =
* Added html icon to the menu.
* Added documents exporting system.
* Added audios exporting system.
* Added linked videos exporting system.
* Fast exporting technique has been utilized.
* Fixed images, audios and documents url not exporting issue.

= 2.0.3 - 30 Septembar 2021 =
* Fixed one little issue.

= 2.0.2 - 14 Septembar 2021 =
* Make plugin compatible with PHP 7.3
* Reduce minimumInputLength to 1 for posts search

= 2.0.1 - 10 Septembar 2021 =
* Fixed little issues.

= 2.0.0 - 9 September 2021 =
* Added "Advanced Settings" Tab.
* Added checkbox "Create index.html on single page exporting".
* Added checkbox "Save all assets files to the specific directory (css, js, images, fonts)".
* Added textarea "Add contents to the header".
* Added textarea "Add contents to the footer".
* Added button "View Last Exported File".
* Added logs percentage system.
* Hide details logs system by default.
* Added skip assets functionalities.
* Fixed unlimited loading issue.
* Fixed minor issues.

= 1.0.3 - 22 April 2021 =
* Fixed little issues.

= 1.0.2 - 13 March 2021 =
* Fixed some major issues.

= 1.0.1 - 28 Jan 2021 =
* Fixed http site data getting issue.
* Fixed same filename conflict issue.
* Fixed single quotation in filename issue.
* increase posts per page to infinite.
* Added homepage option in the page select box.

= 1.0.0 - 22 May 2020 =
* Initialize the plugin

