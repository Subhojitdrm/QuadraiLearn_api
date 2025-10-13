<?php
// For Bluehost shared hosting this is usually 'localhost'
const DB_HOST = 'localhost';
const DB_NAME = 'calmconq_quadravise_library';
const DB_USER = 'calmconq_quadraviseadmin';
const DB_PASS = 'calmconq_quadraviseadmin';

// Turn to true only while debugging (will expose error info)
const DEBUG = false;
// === JWT config ===
const JWT_SECRET   = 'paste-a-long-random-64+char-secret-here'; // change!
const JWT_ISSUER   = 'quadrailearn.quadravise.com';
const JWT_AUDIENCE = 'quadrailearn-users';
const JWT_TTL      = 3600;  // access token lifetime in seconds (1h)

// Login security thresholds
const LOGIN_MAX_ATTEMPTS     = 5;
const LOGIN_WINDOW_MINUTES   = 15;
const LOGIN_LOCK_MINUTES     = 15;
const DEBUG = true; 