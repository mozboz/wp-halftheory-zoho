# wp-halftheory-zoho
Wordpress plugin for synchronizing website user data to the Zoho CRM.

Features:
- Map any Wordpress user field (wp_users, wp_usermeta, Buddypress XProfile) to any Zoho field.
- Simple admin options for updating the Zoho Authtoken and API request URLs.
- Ability to select which Wordpress Actions trigger data synchronization.
- Updates are sent hourly via Cronjob to minimize the number of Zoho API requests.
- Admin receives an email with any errors.
- Compatible with WP Multisite, Buddypress.
- Currently only supports the Zoho Contacts module.

# Custom filters

The following filters are available for plugin/theme customization:
- wpzoho_option_defaults
- wpzoho_deactivation
- wpzoho_uninstall
- wpzoho_exclude_users
- wpzoho_contacts_zoho_fields
- wpzoho_contacts_wp_fields
- wpzoho_contacts_wp_data
- wpzoho_admin_menu_parent
- halftheory_admin_menu_parent
