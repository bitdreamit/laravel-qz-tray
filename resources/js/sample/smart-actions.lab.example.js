/**
 * smart-actions.lab.example.js — starter (lab / clinic / any app)
 * Requires smart-print.js v1.5+ loaded BEFORE this file.
 *
 * The universal functions are BUILT-IN — zero setup, zero define():
 *
 *   printUrl(url)                    PDF / image / HTML — type detected from
 *                                    the HTTP Content-Type (not the extension)
 *   printUrls([url1, url2, url3])    sequential batch
 *   printPdf(x)  / printPdfs([..])   URL | data: URI | raw base64
 *   printImage(x)/ printImages([..]) image URL / data: URI / base64
 *   printHtml(html)                  HTML string | element | selector
 *   printElement('#id' | '.class')   any DOM element (stylesheets cloned)
 *   printPage()                      the current page
 *   printAnyUrl(url)                 readability alias of printUrl
 *
 * Every one of them prints SILENTLY when QZ Tray is running, and falls back
 * to the hidden-iframe browser print automatically when it is not installed /
 * not connected / fails. No if(qz) branches anywhere. Nothing throws.
 *
 * mPDF / Laravel PDF routes (even behind auth middleware) work out of the
 * box: smart-print fetches same-origin URLs THROUGH THE PAGE (session cookies
 * included) and hands QZ Tray the bytes — the tray never needs its own
 * session:
 *
 *   Route::get('/receipt/{id}.pdf', [ReceiptController::class, 'pdf'])
 *       ->middleware('auth');
 *   public function pdf($id) {
 *       return Pdf::loadView('receipt', compact($data))->stream();
 *       // ->download() works identically — printUrl() ignores Content-Disposition
 *   }
 *   printUrl('/receipt/5.pdf');          // that's the whole integration
 */

/*
|--------------------------------------------------------------------------
| Optional only: preset names with baked-in printer/profile choices.
| printLabReceipt / printLabReceipts already exist as aliases of
| printUrl / printUrls — define() here simply overrides their template.
|--------------------------------------------------------------------------
*/
SmartPrint.define({

    // A4 receipt PDF — preset so every call uses the profile automatically
    printLabReceipt: {
        type: 'pdf',
        profile: 'a4',          // 210x297mm — see printQZ() pdf profiles
        // printer: 'HP LaserJet', // omit = remember last printer on this page
        // copies: 1,
        // fallback: 'iframe',  // 'window' | 'newtab' | 'download' | 'queue' | fn
    },

    // Batch version — accepts an array; jobs are queued in order
    printLabReceipts: {
        type: 'pdf',
        profile: 'a4',
    },

    // Work list on the 80mm thermal printer via the 'receipt' alias
    printWorkList: {
        type: 'pdf',
        profile: 'small',       // 80x297mm thermal
        printer: 'receipt',
    },

    // Label printer — base64 PDF or URL
    printLabel: {
        type: 'pdf',
        profile: 'label',       // 100x150mm
        printer: 'label',
    },
});

// OPTIONAL: map friendly aliases to the REAL printer names once, in ONE
// place — your views only ever reference 'receipt' / 'label'.
// SmartPrint.aliasPrinter('receipt', 'XP-80C');
// SmartPrint.aliasPrinter('label',   'Zebra GK420d');

// OPTIONAL: global policies (defaults shown)
// window.QZ_CONFIG = Object.assign(window.QZ_CONFIG || {}, {
//     fallbackMode: 'auto',  // 'auto'|'iframe'|'window'|'newtab'|'download'|'queue'|fn
//     fetchMode: 'auto',     // 'auto' (same-origin)|'always'|'never' — page-fetch engine
//     notify: false,         // true = toast when falling back to the browser
//     onFallback(job) {},    // hook when a job degrades to the browser
// });

/*
|--------------------------------------------------------------------------
| That's it. Usage from anywhere (jQuery handlers, blade, console):
|--------------------------------------------------------------------------
|
|  // Universal names — no define() needed:
|  printUrl('/receipt/5.pdf');                      // mPDF stream/download, auth-safe
|  printUrls([url1, url2, url3]);                   // sequential batch
|  printUrl(url, { copies: 3, printer: 'XP-80C' }); // per-call overrides
|  printPdf('data:application/pdf;base64,JVBERi0x...');
|  printImage('/barcode/123.png');
|  printHtml('<h1>Queue Ticket</h1><p>A-042</p>');
|  printElement('#worklist-table');                 // element by id/class/selector
|  printPage();                                     // print the current page
|
|  // legacy names still work (they are aliases now):
|  printLabReceipt('/receipt/5.pdf');
|  printLabReceipts(pdf_link);                      // array or single — both fine
|
|  // lazy input — resolved at click time:
|  printUrl(() => currentReceiptUrl());
|
|  // plain buttons — no JS at all:
|  <button data-qz-action="printUrl" data-qz-url="/receipt/5.pdf">Print</button>
|  <button data-qz-action="printUrls" data-qz-urls="/r/1.pdf|/r/2.pdf|/r/3.pdf">Batch</button>
|  <button data-qz-action="printElement" data-qz-target="#worklist-table">Worklist</button>
|  <button data-qz-action="printUrl" data-qz-url="/receipt/5.pdf" data-qz-copies="3">x3</button>
|
|  // EVEN SIMPLER — class="smart-print" on any button/link, nothing else:
|  <button class="smart-print" data-qz-url="/receipt/5.pdf">Print</button>
|  <a class="smart-print" href="/receipt/5.pdf">Print receipt</a>
|  <button class="smart-print" data-qz-urls="/r/1.pdf|/r/2.pdf">Batch</button>
|  <button class="smart-print" data-qz-element="#worklist-table">Worklist</button>
|
|  // onclick function routing — built-ins are window globals:
|  <button onclick="printUrl('/receipt/5.pdf')">Print</button>
|  <button onclick="printElement('#worklist-table', { copies: 2 })">Worklist x2</button>
|
|  // need to KNOW whether the tray is there? (never required — but nice
|  // for a status badge):
|  SmartPrint.whenReady(3000).then(s => console.log(s.ok ? 'silent' : 'browser fallback'));
|  console.log(SmartPrint.status());
|
|  // events if you want them:
|  SmartPrint.on('job-completed',  r => toastr.success('Printed'));
|  SmartPrint.on('fallback-print', j => console.log('used browser fallback', j));
|
*/

// If your app ALREADY defines printUrl/printLabReceipt (old iframe version),
// delete the old one — define() intentionally never overwrites existing globals.
