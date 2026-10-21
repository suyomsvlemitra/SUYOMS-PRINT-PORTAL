// FIREBASE CORE INTEGRATION FOR SMART SUYOMS PRINT TOOL
// Project: suyoms-login

const SUYOMS_FIREBASE_CONFIG = {
    apiKey: "AIzaSyDOYUIvE7yWCNEf_K6dHEGfEqtUwLy3rdM",
    authDomain: "suyoms-login.firebaseapp.com",
    databaseURL: "https://suyoms-login-default-rtdb.asia-southeast1.firebasedatabase.app",
    projectId: "suyoms-login",
    storageBucket: "suyoms-login.firebasestorage.app",
    messagingSenderId: "1084425019811",
    appId: "1:1084425019811:web:f036c2b42cb5eb4ee5ba16",
    measurementId: "G-M5D0GP7DBD"
};

// Initialize Firebase App singleton
let suyomsFirebaseApp = null;
let suyomsDb = null;
let suyomsStorage = null;

function getSuyomsFirebase() {
    if (!window.firebase) {
        console.warn("Firebase SDK scripts not loaded yet");
        return null;
    }
    if (!suyomsFirebaseApp) {
        try {
            suyomsFirebaseApp = firebase.app();
        } catch(e) {
            suyomsFirebaseApp = firebase.initializeApp(SUYOMS_FIREBASE_CONFIG);
        }
    }
    if (!suyomsDb && window.firebase.database) {
        suyomsDb = firebase.database();
    }
    if (!suyomsStorage && window.firebase.storage) {
        suyomsStorage = firebase.storage();
    }
    return {
        app: suyomsFirebaseApp,
        db: suyomsDb,
        storage: suyomsStorage
    };
}
