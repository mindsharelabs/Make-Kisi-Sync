# Make Kisi Sync

A WordPress plugin that automatically syncs [WooCommerce Memberships](https://woocommerce.com/products/woocommerce-memberships/) with [Kisi](https://www.getkisi.com/) access control. When a membership becomes active, the member is granted door access in Kisi. When it expires, is cancelled, or is paused, access is revoked.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- WooCommerce
- WooCommerce Memberships
- A Kisi account with admin access

## Installation

1. Upload the `make-kisi-sync` folder to `/wp-content/plugins/`
2. Activate the plugin in **WordPress Admin → Plugins**
3. Go to **Settings → Kisi Access Sync** to configure

## Configuration

### 1. Get your Kisi API Key

In the Kisi dashboard, go to **Account → API Keys** and generate a new key. The key will only be shown once, so copy it immediately.

> The API key must belong to an **admin** of the Kisi organization you want to sync with.

### 2. Find your Kisi Group ID

In the Kisi dashboard, go to **Access → Groups** and click on the group you want members added to. The numeric ID is in the URL:

```
app.kisi.io/groups/12345
                   ^^^^^
                   This is your Group ID
```

### 3. Configure the plugin

In **Settings → Kisi Access Sync**:

| Setting | Description |
|---|---|
| **Kisi API Key** | Your Kisi admin API key |
| **Kisi Group ID** | The group members should be added to |
| **Membership Plans to Sync** | Which plans trigger Kisi sync (leave all unchecked to sync every plan) |
| **Debug Logging** | Writes sync events to the PHP error log |

Use the **Test API Connection** button to confirm your API key is valid before saving.

## How It Works

| Membership status | Kisi action |
|---|---|
| `active`, `complimentary` | User is created in Kisi (if not already), then granted `group_basic` access to the configured group |
| `cancelled`, `expired`, `paused`, `pending` | The role assignment is removed from Kisi |

The plugin stores two pieces of data to keep things idempotent:

- `kisi_user_id` on the WordPress user — so the same Kisi user is reused across multiple memberships
- `kisi_role_assignment_id` on the membership post — so revocation targets the exact assignment that was created

If a user already exists in Kisi with the same email address, the plugin will use the existing account rather than creating a duplicate.

## Troubleshooting

**"Kisi Access Sync requires WooCommerce Memberships to be active."**
WooCommerce Memberships is not installed or not activated.

**"Grant skipped: Kisi group ID not configured."**
No Group ID has been set in the plugin settings.

**"The record could not be found" when creating a role assignment**
The Group ID in settings doesn't match a group in your Kisi organization, or your API key belongs to a different Kisi organization than the group.

**Enable debug logging** in the plugin settings to see detailed log entries (including the exact Kisi user ID and group ID being used) in your PHP error log.

## License

GPL-2.0+
