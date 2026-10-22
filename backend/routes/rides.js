const express = require('express');
const { getAvailableRides, acceptRide, resolveRide } = require('../services/rideService');

const router = express.Router();

router.get('/available', (req, res) => {
  res.json(getAvailableRides());
});

router.post('/accept', (req, res) => {
  const { rideId } = req.body || {};

  if (!rideId) {
    return res.status(400).json({ message: 'rideId requerido' });
  }

  return res.json(acceptRide(rideId));
});

router.patch('/:rideId/status', (req, res) => {
  const { rideId } = req.params;
  const { status } = req.body || {};

  if (!status || !['completed', 'cancelled'].includes(status)) {
    return res.status(400).json({ message: 'status inválido' });
  }

  return res.json(resolveRide(rideId, status));
});

module.exports = router;
