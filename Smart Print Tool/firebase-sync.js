// UNIVERSAL FIREBASE SYNC ENGINE FOR SMART PRINT
// ग्राहकाचा मोबाईल आणि दुकानदाराचा कॉम्प्युटर थेट जोडणारी स्क्रिप्ट

window.initFirebaseSync = function(config, onNewJobCallback) {
    if (!window.firebase) {
        console.error("Firebase SDK not loaded");
        return null;
    }
    
    // Initialize if not already initialized
    let app;
    try {
        app = firebase.app();
    } catch(e) {
        app = firebase.initializeApp(config);
    }
    
    const db = firebase.database();
    const queueRef = db.ref('print_queue');

    // Return methods for send and listen
    return {
        // ग्राहकाने फाईल पाठवण्यासाठी:
        sendJob: function(jobData) {
            return queueRef.push(jobData);
        },
        // दुकानदाराला रिअल-टाईम फाईल मिळण्यासाठी:
        listenJobs: function(callback) {
            queueRef.on('child_added', (snapshot) => {
                const job = snapshot.val();
                job._firebaseKey = snapshot.key;
                if (callback) callback(job);
            });
        },
        // प्रिंट झाल्यावर किंवा डिलीट करण्यासाठी:
        removeJob: function(key) {
            return queueRef.child(key).remove();
        }
    };
};
