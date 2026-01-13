/**
 * ESC/POS Printer Adapter
 * Helper functions for ESC/POS thermal printing
 */
(function() {
    'use strict';

    const ESCPOS = {
        // ESC/POS Commands
        COMMANDS: {
            INIT: '\x1B\x40',           // Initialize printer
            LEFT_ALIGN: '\x1B\x61\x00', // Left alignment
            CENTER_ALIGN: '\x1B\x61\x01', // Center alignment
            RIGHT_ALIGN: '\x1B\x61\x02', // Right alignment
            BOLD_ON: '\x1B\x45\x01',    // Bold on
            BOLD_OFF: '\x1B\x45\x00',   // Bold off
            UNDERLINE_ON: '\x1B\x2D\x01', // Underline on
            UNDERLINE_OFF: '\x1B\x2D\x00', // Underline off
            DOUBLE_HEIGHT: '\x1B\x21\x10', // Double height
            DOUBLE_WIDTH: '\x1B\x21\x20',  // Double width
            NORMAL_TEXT: '\x1B\x21\x00',   // Normal text
            FEED_LINE: '\x0A',           // Feed one line
            CUT_PAPER: '\x1D\x56\x41',   // Cut paper
            CUT_PARTIAL: '\x1D\x56\x42', // Partial cut
            OPEN_DRAWER: '\x1B\x70\x00\x19\xFA', // Open cash drawer
        },

        // Character sets
        CHARACTER_SETS: {
            USA: '\x1B\x52\x00',
            LATIN1: '\x1B\x52\x01',
            LATIN2: '\x1B\x52\x02',
        },

        /**
         * Create a simple receipt
         */
        createReceipt(data) {
            let commands = '';

            // Initialize
            commands += this.COMMANDS.INIT;
            commands += this.CHARACTER_SETS.USA;

            // Header
            commands += this.COMMANDS.CENTER_ALIGN;
            commands += this.COMMANDS.BOLD_ON;
            commands += this.COMMANDS.DOUBLE_HEIGHT;
            commands += (data.storeName || 'STORE NAME') + '\n';
            commands += this.COMMANDS.BOLD_OFF;
            commands += this.COMMANDS.NORMAL_TEXT;

            commands += (data.storeAddress || '123 Street, City') + '\n';
            commands += (data.phone || 'Tel: 0123-456789') + '\n';
            commands += this.COMMANDS.FEED_LINE;

            // Receipt info
            commands += this.COMMANDS.LEFT_ALIGN;
            commands += 'Receipt #: ' + (data.receiptNumber || '000001') + '\n';
            commands += 'Date: ' + (data.date || new Date().toLocaleDateString()) + '\n';
            commands += 'Time: ' + (data.time || new Date().toLocaleTimeString()) + '\n';
            commands += this.COMMANDS.FEED_LINE;

            // Separator
            commands += '--------------------------------\n';

            // Items
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    commands += (item.name || 'Item').padEnd(20, ' ');
                    commands += ('$' + (item.price || '0.00')).padStart(10, ' ') + '\n';

                    if (item.quantity && item.quantity > 1) {
                        commands += '  x' + item.quantity + '\n';
                    }
                });
            }

            // Separator
            commands += '--------------------------------\n';

            // Totals
            commands += 'Subtotal:'.padEnd(25, ' ');
            commands += ('$' + (data.subtotal || '0.00')).padStart(10, ' ') + '\n';

            if (data.tax) {
                commands += 'Tax:'.padEnd(25, ' ');
                commands += ('$' + data.tax).padStart(10, ' ') + '\n';
            }

            if (data.discount) {
                commands += 'Discount:'.padEnd(25, ' ');
                commands += ('-$' + data.discount).padStart(10, ' ') + '\n';
            }

            commands += this.COMMANDS.BOLD_ON;
            commands += 'TOTAL:'.padEnd(25, ' ');
            commands += ('$' + (data.total || '0.00')).padStart(10, ' ') + '\n';
            commands += this.COMMANDS.BOLD_OFF;

            // Footer
            commands += this.COMMANDS.FEED_LINE;
            commands += this.COMMANDS.CENTER_ALIGN;
            commands += 'Thank you for your purchase!\n';
            commands += this.COMMANDS.FEED_LINE;
            commands += this.COMMANDS.FEED_LINE;

            // Cut paper
            commands += this.COMMANDS.CUT_PAPER;

            return commands;
        },

        /**
         * Create a simple label
         */
        createLabel(data) {
            let commands = '';

            commands += this.COMMANDS.INIT;
            commands += this.CHARACTER_SETS.USA;

            commands += this.COMMANDS.CENTER_ALIGN;
            commands += this.COMMANDS.BOLD_ON;
            commands += this.COMMANDS.DOUBLE_HEIGHT;
            commands += (data.title || 'LABEL') + '\n';
            commands += this.COMMANDS.BOLD_OFF;
            commands += this.COMMANDS.NORMAL_TEXT;

            commands += this.COMMANDS.LEFT_ALIGN;
            commands += this.COMMANDS.FEED_LINE;

            if (data.lines && data.lines.length > 0) {
                data.lines.forEach(line => {
                    commands += line + '\n';
                });
            }

            commands += this.COMMANDS.FEED_LINE;
            commands += this.COMMANDS.FEED_LINE;
            commands += this.COMMANDS.CUT_PAPER;

            return commands;
        },

        /**
         * Print raw ESC/POS commands
         */
        printRaw(commands, printer = null) {
            if (!window.smartPrintESC) {
                console.error('Smart Print not loaded');
                return;
            }

            return window.smartPrintESC(commands, printer);
        },

        /**
         * Print a receipt
         */
        printReceipt(data, printer = null) {
            const commands = this.createReceipt(data);
            return this.printRaw(commands, printer);
        },

        /**
         * Print a label
         */
        printLabel(data, printer = null) {
            const commands = this.createLabel(data);
            return this.printRaw(commands, printer);
        },

        /**
         * Open cash drawer
         */
        openDrawer(printer = null) {
            const commands = this.COMMANDS.OPEN_DRAWER;
            return this.printRaw(commands, printer);
        },
    };

    // Export to window
    window.ESCPOS = ESCPOS;

    // Global shortcut
    window.printReceipt = (data, printer) => ESCPOS.printReceipt(data, printer);
    window.printLabel = (data, printer) => ESCPOS.printLabel(data, printer);
    window.openCashDrawer = (printer) => ESCPOS.openDrawer(printer);

})();
