export function adminShell() {
    return {
        adminSidebarOpen: false,
        adminCommandOpen: false,
        adminCommandQuery: '',
        adminCommandItems: [],

        openAdminCommandPalette() {
            this.collectAdminCommands();
            this.adminCommandQuery = '';
            this.adminCommandOpen = true;

            this.$nextTick(() => {
                this.$refs.adminCommandInput?.focus();
            });
        },

        closeAdminCommandPalette() {
            this.adminCommandOpen = false;
            this.adminCommandQuery = '';
        },

        collectAdminCommands() {
            const catalogItems = this.collectCatalogCommands();
            const links = [
                ...document.querySelectorAll('.admin-sidebar nav:not([aria-hidden="true"]) a[href]'),
                ...document.querySelectorAll('[aria-label="Admin module tabs"] a[href]'),
            ];
            const seen = new Set();
            const items = catalogItems
                .filter((item) => item.href && item.label)
                .map((item) => ({
                    href: item.href,
                    label: item.label,
                    group: item.group || '',
                    haystack: `${item.label} ${item.href}`.toLowerCase(),
                }));

            items.forEach((item) => {
                seen.add(item.href);
            });

            links.forEach((link) => {
                const href = link.href;
                const rawLabel = (link.innerText || '').replace(/\s+/g, ' ').trim();

                if (!href || !rawLabel || seen.has(href)) {
                    return;
                }

                seen.add(href);
                const group = this.commandGroupFor(link);
                const label = group && !rawLabel.toLowerCase().startsWith(group.toLowerCase())
                    ? `${group} / ${rawLabel}`
                    : rawLabel;

                items.push({
                    href,
                    label,
                    group,
                    haystack: `${label} ${href}`.toLowerCase(),
                });
            });

            this.adminCommandItems = items;
        },

        collectCatalogCommands() {
            const source = document.querySelector('[data-admin-command-items]');

            if (!source?.textContent) {
                return [];
            }

            try {
                const parsed = JSON.parse(source.textContent);

                return Array.isArray(parsed) ? parsed : [];
            } catch {
                return [];
            }
        },

        commandGroupFor(link) {
            const directGroup = link.closest('.nav-group');
            const groupButton = directGroup?.querySelector(':scope > .nav-group-btn span');
            const group = (groupButton?.innerText || '').replace(/\s+/g, ' ').trim();

            if (group) {
                return group;
            }

            const moduleLabel = document.querySelector('[aria-labelledby="admin-module-heading"] p')?.innerText;

            return (moduleLabel || '').replace(/\s+/g, ' ').trim();
        },

        filteredAdminCommands() {
            const query = this.adminCommandQuery.trim().toLowerCase();

            if (query === '') {
                return this.adminCommandItems.slice(0, 12);
            }

            return this.adminCommandItems
                .filter((item) => item.haystack.includes(query))
                .slice(0, 12);
        },
    };
}
