const express = require('express');
const { getDriverStatus, updateDriverStatus } = require('../services/driverService');

const router = express.Router();

router.get('/status', (req, res) => {
  res.json(getDriverStatus());
});

router.patch('/status', (req, res) => {
  const { status } = req.body || {};

  if (!status) {
    return res.status(400).json({ message: 'status es requerido' });
  }

  return res.json(updateDriverStatus(status));
});

module.exports = router;
