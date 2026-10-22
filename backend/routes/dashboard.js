const express = require('express');
const { getSummary } = require('../services/dashboardService');

const router = express.Router();

router.get('/summary', (req, res) => {
  res.json(getSummary());
});

module.exports = router;
