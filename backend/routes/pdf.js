const express = require('express');
const { generateDailyBalanceReport } = require('../services/pdfService');

const router = express.Router();

router.post('/export', async (req, res) => {
  try {
    const buffer = await generateDailyBalanceReport();
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', 'attachment; filename="reporte-halcones.pdf"');
    return res.send(buffer);
  } catch (error) {
    return res.status(500).json({ message: 'Error al generar el PDF', error: error.message });
  }
});

module.exports = router;
