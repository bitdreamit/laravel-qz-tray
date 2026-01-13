/**
 * Raw Print Adapter
 * Generic raw printer commands
 */
(function() {
    'use strict';

    const RawPrint = {
        /**
         * Print raw commands to printer
         */
        printRaw(commands, printer = null, type = 'raw') {
            if (!window.SmartPrint) {
                console.error('Smart Print not loaded');
                return Promise.reject(new Error('Smart Print not loaded'));
            }

            return window.SmartPrint.printRaw(commands, type, printer);
        },

        /**
         * Print text with basic formatting
         */
        printText(text, options = {}) {
            const defaults = {
                printer: null,
                align: 'left',
                bold: false,
                underline: false,
                fontSize: 'normal',
                copies: 1,
            };

            const settings = { ...defaults, ...options };

            let commands = '';

            // Add formatting based on options
            if (settings.align === 'center') {
                commands += '\x1B\x61\x01'; // ESC a 1 (Center)
            } else if (settings.align === 'right') {
                commands += '\x1B\x61\x02'; // ESC a 2 (Right)
            } else {
                commands += '\x1B\x61\x00'; // ESC a 0 (Left)
            }

            if (settings.bold) {
                commands += '\x1B\x45\x01'; // ESC E 1 (Bold on)
            }

            if (settings.underline) {
                commands += '\x1B\x2D\x01'; // ESC - 1 (Underline on)
            }

            if (settings.fontSize === 'large') {
                commands += '\x1D\x21\x11'; // GS ! 17 (Double width and height)
            } else if (settings.fontSize === 'small') {
                commands += '\x1B\x4D\x01'; // ESC M 1 (Font B - small)
            }

            // Add text
            commands += text;

            // Reset formatting
            commands += '\x1B\x40'; // ESC @ (Initialize)

            return this.printRaw(commands, settings.printer);
        },

        /**
         * Print a line (useful for receipts)
         */
        printLine(char = '-', length = 32, printer = null) {
            const line = char.repeat(length) + '\n';
            return this.printText(line, { printer, align: 'left' });
        },

        /**
         * Print a table
         */
        printTable(data, options = {}) {
            const defaults = {
                printer: null,
                columns: [],
                border: true,
                header: true,
            };

            const settings = { ...defaults, ...options };
            let output = '';

            if (settings.header && settings.columns.length > 0) {
                // Header
                settings.columns.forEach(col => {
                    output += col.title.padEnd(col.width || 20, ' ');
                });
                output += '\n';

                // Header separator
                if (settings.border) {
                    settings.columns.forEach(col => {
                        output += '-'.repeat(col.width || 20);
                    });
                    output += '\n';
                }
            }

            // Data rows
            data.forEach(row => {
                settings.columns.forEach(col => {
                    const value = row[col.key] || '';
                    output += String(value).padEnd(col.width || 20, ' ');
                });
                output += '\n';
            });

            return this.printText(output, { printer: settings.printer });
        },

        /**
         * Print barcode
         */
        printBarcode(data, type = 'CODE128', printer = null) {
            let commands = '';

            switch(type.toUpperCase()) {
                case 'CODE128':
                    commands += '\x1D\x68\x64'; // Height
                    commands += '\x1D\x77\x02'; // Width
                    commands += '\x1D\x48\x02'; // HRI (Human Readable Interpretation) below
                    commands += '\x1D\x6B\x49'; // CODE128
                    commands += String(data.length);
                    commands += data;
                    break;

                case 'CODE39':
                    commands += '\x1D\x68\x64';
                    commands += '\x1D\x77\x02';
                    commands += '\x1D\x48\x02';
                    commands += '\x1D\x6B\x04'; // CODE39
                    commands += data;
                    commands += '\x00'; // Null terminator
                    break;

                case 'EAN13':
                    if (data.length === 12 || data.length === 13) {
                        commands += '\x1D\x68\x64';
                        commands += '\x1D\x77\x02';
                        commands += '\x1D\x48\x02';
                        commands += '\x1D\x6B\x02'; // EAN13
                        commands += data.substring(0, 12);
                    }
                    break;
            }

            return this.printRaw(commands, printer);
        },

        /**
         * Cut paper
         */
        cutPaper(printer = null, partial = false) {
            const command = partial ? '\x1D\x56\x42' : '\x1D\x56\x41';
            return this.printRaw(command, printer);
        },

        /**
         * Open cash drawer
         */
        openCashDrawer(printer = null) {
            // Pulse pin 2 (usually the first drawer)
            const command = '\x1B\x70\x00\x19\xFA';
            return this.printRaw(command, printer);
        },

        /**
         * Print image (basic monochrome)
         * Note: This is a simplified implementation
         */
        printImage(imageData, width = 384, printer = null) {
            // This would require actual image processing
            // For now, just log a warning
            console.warn('Image printing requires additional image processing');
            return Promise.resolve();
        },

        /**
         * Test printer
         */
        testPrinter(printer = null) {
            const testText =
                '================================\n' +
                '       PRINTER TEST PAGE        \n' +
                '================================\n' +
                'Normal text\n' +
                '\x1B\x45\x01Bold text\x1B\x45\x00\n' +
                '\x1B\x2D\x01Underlined text\x1B\x2D\x00\n' +
                '\x1D\x21\x11Large text\x1D\x21\x00\n' +
                '\x1B\x4D\x01Small text\x1B\x4D\x00\n' +
                '\x1B\x61\x01Centered text\x1B\x61\x00\n' +
                '\x1B\x61\x02Right aligned\x1B\x61\x00\n' +
                '================================\n' +
                'Test completed successfully!\n' +
                '================================\n';

            return this.printText(testText, { printer });
        },
    };

    // Export to window
    window.RawPrint = RawPrint;

    // Global shortcuts
    window.printText = (text, options) => RawPrint.printText(text, options);
    window.printTable = (data, options) => RawPrint.printTable(data, options);
    window.printBarcode = (data, type, printer) => RawPrint.printBarcode(data, type, printer);
    window.cutPaper = (printer, partial) => RawPrint.cutPaper(printer, partial);
    window.openCashDrawer = (printer) => RawPrint.openCashDrawer(printer);
    window.testPrinter = (printer) => RawPrint.testPrinter(printer);

})();
