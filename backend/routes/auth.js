const express = require('express');
const jwt = require('jsonwebtoken');
const { jwtSecret } = require('../config');

const router = express.Router();

router.post('/login', (req, res) => {
  const { email, password } = req.body || {};

  if (!email || !password) {
    return res.status(400).json({ message: 'Email y password requeridos' });
  }

  const user = {
    id: 'driver-001',
    name: 'Julián V.',
    email,
    role: 'driver',
    mobile: 'HLC-102',
  };

  const token = jwt.sign(user, jwtSecret, { expiresIn: '12h' });

  return res.json({ token, user });
});

module.exports = router;
