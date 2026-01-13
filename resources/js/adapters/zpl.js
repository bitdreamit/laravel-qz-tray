/**
 * ZPL (Zebra Programming Language) Adapter
 * Helper functions for Zebra label printing
 */
(function() {
    'use strict';

    const ZPL = {
            // ZPL Commands
            COMMANDS: {
                START: '^XA',           // Start label
                END: '^XZ',             // End label
                FIELD_ORIGIN: '^FO',    // Field origin (x,y)
                FIELD_DATA: '^FD',      // Field data
                FIELD_SEPARATOR: '^FS', // Field separator
                FONT: '^A',             // Font
                BARCODE: '^B',          // Barcode
                QR_CODE: '^BQ',         // QR Code
                BOX: '^GB',             // Draw box
                LINE: '^GD',            // Draw line
            },

            // Fonts
            FONTS: {
                DEFAULT: '0N',          // Default font
                LARGE: '0N,50,50',      // Large font
                MEDIUM: '0N,30,30',     // Medium font
                SMALL: '0N,20,20',      // Small font
            },

            // Barcode types
            BARCODES: {
                CODE128: 'N',           // Code 128
                CODE39: '3',            // Code 39
                QR: 'Q',                // QR Code
                EAN13: 'E',             // EAN-13
                UPC_A: 'A',             // UPC-A
        },

        /**
         * Create a simple shipping label
         */
        createShippingLabel(data) {
        let zpl = '';

        // Start label
        zpl += this.COMMANDS.START;

        // From address (top left)
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,50';
        zpl += this.COMMANDS.FONT + this.FONTS.LARGE;
        zpl += this.COMMANDS.FIELD_DATA + 'FROM:' + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '50,100';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + (data.fromName || 'John Doe') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '50,140';
        zpl += this.COMMANDS.FIELD_DATA + (data.fromAddress || '123 Street') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '50,180';
        zpl += this.COMMANDS.FIELD_DATA + (data.fromCity || 'City, State 12345') + this.COMMANDS.FIELD_SEPARATOR;

        // To address (top right)
        zpl += this.COMMANDS.FIELD_ORIGIN + '400,50';
        zpl += this.COMMANDS.FONT + this.FONTS.LARGE;
        zpl += this.COMMANDS.FIELD_DATA + 'TO:' + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '400,100';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + (data.toName || 'Jane Smith') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '400,140';
        zpl += this.COMMANDS.FIELD_DATA + (data.toAddress || '456 Avenue') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '400,180';
        zpl += this.COMMANDS.FIELD_DATA + (data.toCity || 'City, State 67890') + this.COMMANDS.FIELD_SEPARATOR;

        // Separator line
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,250';
        zpl += this.COMMANDS.BOX + '700,5,3' + this.COMMANDS.FIELD_SEPARATOR;

        // Tracking info
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,280';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + 'Tracking #:' + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '50,320';
        zpl += this.COMMANDS.FONT + this.FONTS.LARGE;
        zpl += this.COMMANDS.FIELD_DATA + (data.trackingNumber || 'TRK123456789') + this.COMMANDS.FIELD_SEPARATOR;

        // Barcode
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,400';
        zpl += this.COMMANDS.BARCODE + this.BARCODES.CODE128 + ',100,Y,100,N,N';
        zpl += this.COMMANDS.FIELD_DATA + (data.trackingNumber || 'TRK123456789') + this.COMMANDS.FIELD_SEPARATOR;

        // Weight and service
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,550';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + 'Weight: ' + (data.weight || '2.5 kg') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.FIELD_ORIGIN + '300,550';
        zpl += this.COMMANDS.FIELD_DATA + 'Service: ' + (data.service || 'Express') + this.COMMANDS.FIELD_SEPARATOR;

        // End label
        zpl += this.COMMANDS.END;

        return zpl;
    },

    /**
     * Create a simple product label
     */
    createProductLabel(data) {
        let zpl = '';

        zpl += this.COMMANDS.START;

        // Product name
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,50';
        zpl += this.COMMANDS.FONT + this.FONTS.LARGE;
        zpl += this.COMMANDS.FIELD_DATA + (data.productName || 'Product Name') + this.COMMANDS.FIELD_SEPARATOR;

        // SKU
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,120';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + 'SKU: ' + (data.sku || 'SKU12345') + this.COMMANDS.FIELD_SEPARATOR;

        // Price
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,170';
        zpl += this.COMMANDS.FONT + this.FONTS.LARGE;
        zpl += this.COMMANDS.FIELD_DATA + '$' + (data.price || '19.99') + this.COMMANDS.FIELD_SEPARATOR;

        // Barcode
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,250';
        zpl += this.COMMANDS.BARCODE + this.BARCODES.CODE128 + ',80,Y,80,N,N';
        zpl += this.COMMANDS.FIELD_DATA + (data.barcode || data.sku || '123456789012') + this.COMMANDS.FIELD_SEPARATOR;

        // Barcode human readable
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,350';
        zpl += this.COMMANDS.FONT + this.FONTS.SMALL;
        zpl += this.COMMANDS.FIELD_DATA + (data.barcode || data.sku || '123456789012') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.END;

        return zpl;
    },

    /**
     * Create a QR code label
     */
    createQRLabel(data) {
        let zpl = '';

        zpl += this.COMMANDS.START;

        // Title
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,50';
        zpl += this.COMMANDS.FONT + this.FONTS.MEDIUM;
        zpl += this.COMMANDS.FIELD_DATA + (data.title || 'Scan QR Code') + this.COMMANDS.FIELD_SEPARATOR;

        // QR Code
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,100';
        zpl += this.COMMANDS.QR_CODE + 'N,2,Q,7';
        zpl += this.COMMANDS.FIELD_DATA + (data.qrData || 'https://example.com') + this.COMMANDS.FIELD_SEPARATOR;

        // URL below QR
        zpl += this.COMMANDS.FIELD_ORIGIN + '50,300';
        zpl += this.COMMANDS.FONT + this.FONTS.SMALL;
        zpl += this.COMMANDS.FIELD_DATA + (data.url || 'example.com') + this.COMMANDS.FIELD_SEPARATOR;

        zpl += this.COMMANDS.END;

        return zpl;
    },

    /**
     * Print raw ZPL commands
     */
    printRaw(zpl, printer = null) {
        if (!window.smartPrintZPL) {
            console.error('Smart Print not loaded');
            return;
        }

        return window.smartPrintZPL(zpl, printer);
    },

    /**
     * Print shipping label
     */
    printShippingLabel(data, printer = null) {
        const zpl = this.createShippingLabel(data);
        return this.printRaw(zpl, printer);
    },

    /**
     * Print product label
     */
    printProductLabel(data, printer = null) {
        const zpl = this.createProductLabel(data);
        return this.printRaw(zpl, printer);
    },

    /**
     * Print QR code label
     */
    printQRLabel(data, printer = null) {
        const zpl = this.createQRLabel(data);
        return this.printRaw(zpl, printer);
    },

    /**
     * Generate ZPL from template
     */
    generateFromTemplate(template, data) {
        let zpl = template;

        // Replace placeholders
        for (const [key, value] of Object.entries(data)) {
            const placeholder = `{{${key}}}`;
            zpl = zpl.replace(new RegExp(placeholder, 'g'), value);
        }

        return zpl;
    },
};

    // Export to window
    window.ZPL = ZPL;

    // Global shortcuts
    window.printShippingLabel = (data, printer) => ZPL.printShippingLabel(data, printer);
    window.printProductLabel = (data, printer) => ZPL.printProductLabel(data, printer);
    window.printQRLabel = (data, printer) => ZPL.printQRLabel(data, printer);

})();
