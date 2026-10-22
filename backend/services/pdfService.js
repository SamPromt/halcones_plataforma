const PDFDocument = require('pdfkit');

function formatMoney(value) {
  return `$${value.toLocaleString('es-CO')}`;
}

function generateDailyBalanceReport() {
  return new Promise((resolve, reject) => {
    const cash = 7500;
    const transfer = 9000;
    const total = cash + transfer;

    const doc = new PDFDocument({ size: 'A4', margin: 40 });
    const chunks = [];

    doc.on('data', (chunk) => chunks.push(chunk));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);

    doc.fillColor('#c8102e').fontSize(22).font('Helvetica-Bold').text('HALCONES', { align: 'left' });
    doc.fillColor('#333333').fontSize(10).font('Helvetica').text('Movilidad & Despacho - Fusagasugá');
    doc.moveDown(1.3);
    doc.fillColor('#111111').fontSize(18).font('Helvetica-Bold').text('Reporte de Balance Diario', { align: 'right' });
    doc.fillColor('#444444').fontSize(10).font('Helvetica').text(new Date().toLocaleDateString('es-CO', { dateStyle: 'full' }), { align: 'right' });

    doc.moveDown(1.5);
    doc.fillColor('#111111').fontSize(11).font('Helvetica').text('Conductor: Julián V. (Móvil HLC-102)');
    doc.text('Vehículo: Renault Logan TZV-412');
    doc.moveDown(1.5);

    doc.fillColor('#ffffff').font('Helvetica-Bold').fontSize(11);
    doc.rect(40, doc.y, 515, 24).fill('#f3f4f6');
    doc.fillColor('#111111');
    doc.text('Concepto', 60, doc.y + 7);
    doc.text('Valor', 500, doc.y + 7, { align: 'right' });
    doc.moveDown(1.5);

    doc.fillColor('#111111').font('Helvetica').fontSize(11);
    doc.text('Ingresos en Efectivo', 60, doc.y + 18);
    doc.text(formatMoney(cash), 500, doc.y + 18, { align: 'right' });
    doc.text('Ingresos por Transferencia (Nequi/Banco)', 60, doc.y + 40);
    doc.text(formatMoney(transfer), 500, doc.y + 40, { align: 'right' });

    doc.rect(40, doc.y + 58, 515, 28).fill('#fef2f2');
    doc.fillColor('#c8102e').font('Helvetica-Bold').fontSize(13);
    doc.text('TOTAL GENERADO', 60, doc.y + 66);
    doc.text(formatMoney(total), 500, doc.y + 66, { align: 'right' });

    doc.fillColor('#666666').font('Helvetica').fontSize(9);
    doc.text('Documento generado electrónicamente por Halcones Sistema de Control.', 0, 760, { align: 'center' });
    doc.end();
  });
}

module.exports = { generateDailyBalanceReport };
