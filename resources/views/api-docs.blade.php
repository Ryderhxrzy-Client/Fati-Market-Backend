<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - Fati-Market</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            text-align: center;
        }

        header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        header p {
            font-size: 1.1em;
            color: #666;
        }

        .content-wrapper {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .sidebar {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .sidebar h3 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 1.1em;
        }

        .sidebar ul {
            list-style: none;
        }

        .sidebar li {
            margin-bottom: 10px;
        }

        .sidebar a {
            color: #666;
            text-decoration: none;
            display: block;
            padding: 8px 12px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .sidebar a:hover {
            background: #f0f0f0;
            color: #667eea;
            padding-left: 20px;
        }

        main {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        section {
            margin-bottom: 50px;
            scroll-margin-top: 20px;
        }

        h2 {
            color: #667eea;
            font-size: 2em;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }

        h3 {
            color: #764ba2;
            font-size: 1.4em;
            margin-top: 25px;
            margin-bottom: 15px;
        }

        h4 {
            color: #555;
            font-size: 1.1em;
            margin-top: 15px;
            margin-bottom: 10px;
        }

        p {
            margin-bottom: 15px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            overflow-x: auto;
            display: block;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f8f8;
            color: #667eea;
            font-weight: 600;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            overflow-x: auto;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.9em;
            line-height: 1.5;
            border-left: 4px solid #667eea;
        }

        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            color: #155724;
        }

        .error-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            color: #721c24;
        }

        .info-box {
            background: #cce5ff;
            border-left: 4px solid #0066cc;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            color: #003d99;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            color: #856404;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            margin: 0 5px 0 0;
        }

        .badge-success {
            background: #d4edda;
            color: #28a745;
        }

        .badge-error {
            background: #f8d7da;
            color: #dc3545;
        }

        .badge-info {
            background: #cce5ff;
            color: #0066cc;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }

        .endpoint-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
        }

        .endpoint-card h4 {
            margin-top: 0;
            color: #667eea;
        }

        .method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85em;
            margin-right: 10px;
        }

        .method-post {
            background: #667eea;
            color: white;
        }

        .method-get {
            background: #28a745;
            color: white;
        }

        .method-put {
            background: #ffc107;
            color: white;
        }

        .method-delete {
            background: #dc3545;
            color: white;
        }

        .param-required {
            color: #dc3545;
            font-weight: 600;
        }

        footer {
            text-align: center;
            padding: 20px;
            color: white;
            margin-top: 40px;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
            }

            header h1 {
                font-size: 1.8em;
            }

            h2 {
                font-size: 1.5em;
            }

            main {
                padding: 20px;
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.9em;
            }

            th, td {
                padding: 8px 10px;
            }
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-201 {
            background: #28a745;
        }

        .status-422 {
            background: #ffc107;
        }

        .status-500 {
            background: #dc3545;
        }

        .copy-button {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            margin-top: 10px;
            transition: all 0.3s ease;
        }

        .copy-button:hover {
            background: #764ba2;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📱 Fati-Market API Documentation</h1>
            <p>Student Registration Endpoint for Kotlin Mobile App</p>
        </header>

        <div class="content-wrapper">
            <!-- Sidebar -->
            <aside class="sidebar">
                <h3>📑 Navigation</h3>
                <ul>
                    <li><a href="#overview">Overview</a></li>
                    <li><a href="#parameters">Parameters</a></li>
                    <li><a href="#responses">Responses</a></li>
                    <li><a href="#errors">Error Handling</a></li>
                    <li><a href="#kotlin">Kotlin Implementation</a></li>
                    <li><a href="#database">Database Schema</a></li>
                    <li><a href="#workflow">Admin Workflow</a></li>
                    <li><a href="#testing">Testing</a></li>
                </ul>
            </aside>

            <!-- Main Content -->
            <main>
                <!-- Overview Section -->
                <section id="overview">
                    <h2>🎯 Overview</h2>
                    <p>Registration endpoint for new students joining the Fati-Market marketplace. Students register with their OLFU email, create a password, and submit a photo of their student ID for verification.</p>

                    <div class="endpoint-card">
                        <h4>Endpoint Details</h4>
                        <table>
                            <tr>
                                <th>Property</th>
                                <th>Value</th>
                            </tr>
                            <tr>
                                <td><strong>URL</strong></td>
                                <td><code>/api/register</code></td>
                            </tr>
                            <tr>
                                <td><strong>Method</strong></td>
                                <td><span class="method method-post">POST</span></td>
                            </tr>
                            <tr>
                                <td><strong>Content-Type</strong></td>
                                <td><code>multipart/form-data</code></td>
                            </tr>
                            <tr>
                                <td><strong>Authentication</strong></td>
                                <td>❌ Not required</td>
                            </tr>
                            <tr>
                                <td><strong>Response Code</strong></td>
                                <td>201 (Created)</td>
                            </tr>
                        </table>
                    </div>
                </section>

                <!-- Parameters Section -->
                <section id="parameters">
                    <h2>📋 Request Parameters</h2>
                    <p>All fields are required unless otherwise noted. Image file must be sent as multipart/form-data.</p>

                    <table>
                        <tr>
                            <th>Field Name</th>
                            <th>Type</th>
                            <th>Required</th>
                            <th>Constraints</th>
                            <th>Example</th>
                        </tr>
                        <tr>
                            <td><code>first_name</code></td>
                            <td>string</td>
                            <td><span class="param-required">✓</span></td>
                            <td>Max 255 chars</td>
                            <td>"Juan"</td>
                        </tr>
                        <tr>
                            <td><code>last_name</code></td>
                            <td>string</td>
                            <td><span class="param-required">✓</span></td>
                            <td>Max 255 chars</td>
                            <td>"Dela Cruz"</td>
                        </tr>
                        <tr>
                            <td><code>email</code></td>
                            <td>string</td>
                            <td><span class="param-required">✓</span></td>
                            <td>Must end with @olfu.edu.ph, unique</td>
                            <td>"juan@olfu.edu.ph"</td>
                        </tr>
                        <tr>
                            <td><code>password</code></td>
                            <td>string</td>
                            <td><span class="param-required">✓</span></td>
                            <td>Min 8 characters</td>
                            <td>"SecurePass123"</td>
                        </tr>
                        <tr>
                            <td><code>password_confirmation</code></td>
                            <td>string</td>
                            <td><span class="param-required">✓</span></td>
                            <td>Must match password</td>
                            <td>"SecurePass123"</td>
                        </tr>
                        <tr>
                            <td><code>student_id_photo</code></td>
                            <td>file</td>
                            <td><span class="param-required">✓</span></td>
                            <td>jpg/jpeg/png, max 5MB</td>
                            <td>id_photo.jpg</td>
                        </tr>
                    </table>
                </section>

                <!-- Success Response -->
                <section id="responses">
                    <h2>✅ Success Response</h2>
                    <p>Returned when registration is successful and all data is stored in the database.</p>

                    <div class="success-box">
                        <strong>HTTP Status: 201 Created</strong>
                    </div>

                    <h4>Response Body:</h4>
                    <div class="code-block">
{
  "message": "Registration successful. Please wait for admin approval.",
  "data": {
    "user_id": 42,
    "student_id": 15,
    "email": "juan.delacruz@olfu.edu.ph",
    "first_name": "Juan",
    "last_name": "Dela Cruz"
  }
}
                    </div>

                    <h4>Response Fields:</h4>
                    <ul>
                        <li><strong>message</strong>: Confirmation message for the user</li>
                        <li><strong>user_id</strong>: Unique user identifier (for login)</li>
                        <li><strong>student_id</strong>: Unique student identifier</li>
                        <li><strong>email</strong>: Registered email address</li>
                        <li><strong>first_name</strong>: Student's first name</li>
                        <li><strong>last_name</strong>: Student's last name</li>
                    </ul>
                </section>

                <!-- Error Handling -->
                <section id="errors">
                    <h2>❌ Error Handling</h2>

                    <h3>Validation Errors (HTTP 422)</h3>

                    <div class="endpoint-card">
                        <h4>❌ Email not from @olfu.edu.ph</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field must end with @olfu.edu.ph."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Use your OLFU school email (format: yourname@olfu.edu.ph)</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Email already registered</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Use a different email or contact support to recover your account</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Password too short</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "password": ["The password field must be at least 8 characters."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Use a password with at least 8 characters (include letters, numbers, symbols for better security)</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Passwords don't match</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "password": ["The password field confirmation does not match."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Make sure both password fields are identical (check for typos)</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Missing required field</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "first_name": ["The first name field is required."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Fill in all required fields (marked with *)</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Invalid photo format</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "student_id_photo": ["The student id photo field must be a valid image."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Upload a JPG, JPEG, or PNG image file</p>
                    </div>

                    <div class="endpoint-card">
                        <h4>❌ Photo file too large</h4>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "student_id_photo": ["The student id photo field must not be greater than 5120 kilobytes."]
  }
}
                        </div>
                        <p><strong>Fix:</strong> Compress your image or choose a smaller photo (max 5MB)</p>
                    </div>

                    <h3>Server Errors (HTTP 500)</h3>

                    <div class="error-box">
                        <strong>Server Error:</strong> Registration failed<br>
                        <p style="margin-top: 10px;">This could be caused by Cloudinary upload issues or database errors.</p>
                        <p><strong>Fix:</strong></p>
                        <ul>
                            <li>Check your internet connection</li>
                            <li>Verify server logs for detailed error</li>
                            <li>Contact admin if problem persists</li>
                        </ul>
                    </div>

                    <h3>Network Errors</h3>

                    <div class="warning-box">
                        <strong>Connection Timeout (60 seconds):</strong> Failed to connect to /api/register
                        <p style="margin-top: 10px;"><strong>Fix:</strong></p>
                        <ul>
                            <li>Check if server is running: <code>php artisan serve</code></li>
                            <li>Verify base URL is correct</li>
                            <li>Check internet connection</li>
                            <li>Try again after a few seconds</li>
                        </ul>
                    </div>
                </section>

                <!-- Kotlin Implementation -->
                <section id="kotlin">
                    <h2>📱 Kotlin Implementation</h2>

                    <h3>Using OkHttp3 (Recommended)</h3>

                    <h4>Step 1: Add Dependencies (build.gradle)</h4>
                    <div class="code-block">
dependencies {
    implementation 'com.squareup.okhttp3:okhttp:4.11.0'
    implementation 'com.google.code.gson:gson:2.10.1'
}
                    </div>

                    <h4>Step 2: Create AuthService Class</h4>
                    <div class="code-block">
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import java.io.File
import com.google.gson.Gson

class AuthService {
    private val client = OkHttpClient()
    private val gson = Gson()
    private val baseUrl = "http://your-api.com" // Change to your server URL

    fun registerStudent(
        firstName: String,
        lastName: String,
        email: String,
        password: String,
        passwordConfirmation: String,
        photoFile: File,
        onSuccess: (RegisterResponse) -> Unit,
        onError: (String) -> Unit
    ) {
        // Validate before sending
        if (!email.endsWith("@olfu.edu.ph")) {
            onError("Email must end with @olfu.edu.ph")
            return
        }
        if (password != passwordConfirmation) {
            onError("Passwords do not match")
            return
        }
        if (password.length < 8) {
            onError("Password must be at least 8 characters")
            return
        }
        if (!photoFile.exists()) {
            onError("Photo file not found")
            return
        }

        // Build request
        val requestBody = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart("first_name", firstName)
            .addFormDataPart("last_name", lastName)
            .addFormDataPart("email", email)
            .addFormDataPart("password", password)
            .addFormDataPart("password_confirmation", passwordConfirmation)
            .addFormDataPart(
                "student_id_photo",
                photoFile.name,
                photoFile.asRequestBody("image/jpeg".toMediaType())
            )
            .build()

        val request = Request.Builder()
            .url("$baseUrl/api/register")
            .post(requestBody)
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                onError("Network error: ${e.message}")
            }

            override fun onResponse(call: Call, response: Response) {
                val body = response.body?.string()
                when (response.code) {
                    201 -> {
                        val registerResponse = gson.fromJson(body, RegisterResponse::class.java)
                        onSuccess(registerResponse)
                    }
                    422 -> {
                        val errorResponse = gson.fromJson(body, ErrorResponse::class.java)
                        onError(errorResponse.message)
                    }
                    else -> {
                        onError("Error ${response.code}: Registration failed")
                    }
                }
            }
        })
    }
}

// Data classes
data class RegisterResponse(
    val message: String,
    val data: RegisterData
)

data class RegisterData(
    val user_id: Int,
    val student_id: Int,
    val email: String,
    val first_name: String,
    val last_name: String
)

data class ErrorResponse(
    val message: String,
    val errors: Map<String, List<String>>? = null
)
                    </div>

                    <h4>Step 3: Use in Activity/Fragment</h4>
                    <div class="code-block">
class RegistrationActivity : AppCompatActivity() {
    private val authService = AuthService()

    private fun registerStudent() {
        val photoFile = File(imageUri.path) // Your picked image

        authService.registerStudent(
            firstName = "Juan",
            lastName = "Dela Cruz",
            email = "juan.delacruz@olfu.edu.ph",
            password = "SecurePass123",
            passwordConfirmation = "SecurePass123",
            photoFile = photoFile,
            onSuccess = { response ->
                Toast.makeText(
                    this,
                    "✅ Registration successful!",
                    Toast.LENGTH_SHORT
                ).show()
                // Navigate to next screen
            },
            onError = { error ->
                Toast.makeText(this, "❌ $error", Toast.LENGTH_LONG).show()
            }
        )
    }
}
                    </div>
                </section>

                <!-- Database Schema -->
                <section id="database">
                    <h2>🗄️ Database Schema</h2>
                    <p>When registration succeeds, three records are created in the database:</p>

                    <h3>1. users Table</h3>
                    <div class="code-block">
user_id: INT (PRIMARY KEY, auto-increment)
email: VARCHAR (UNIQUE)
password: VARCHAR (bcrypt hashed)
wallet_points: INT (default: 0)
role: ENUM ('student', 'admin') - default: 'student'
is_active: BOOLEAN (default: FALSE) ← Waiting for admin approval
created_at: TIMESTAMP
updated_at: TIMESTAMP
                    </div>

                    <h3>2. student_information Table</h3>
                    <div class="code-block">
student_id: INT (PRIMARY KEY, auto-increment)
user_id: INT (FOREIGN KEY → users.user_id)
first_name: VARCHAR
last_name: VARCHAR
created_at: TIMESTAMP
updated_at: TIMESTAMP
                    </div>

                    <h3>3. student_verification Table</h3>
                    <div class="code-block">
student_id: INT (PRIMARY KEY, auto-increment)
user_id: INT (FOREIGN KEY → users.user_id)
link: TEXT (Cloudinary secure URL of student ID photo)
is_verified: BOOLEAN (default: FALSE) ← Waiting for admin approval
created_at: TIMESTAMP
updated_at: TIMESTAMP
                    </div>
                </section>

                <!-- Admin Workflow -->
                <section id="workflow">
                    <h2>👨‍💼 Admin Approval Workflow</h2>
                    <p>Students cannot access the marketplace until Ofelia (admin) reviews and approves their registration.</p>

                    <div class="info-box">
                        <strong>Step-by-step process:</strong>
                        <ol>
                            <li><strong>Student Registers</strong> → Creates records with <code>is_active=false</code>, <code>is_verified=false</code></li>
                            <li><strong>Ofelia Views Dashboard</strong> → Sees pending student verifications</li>
                            <li><strong>Ofelia Reviews Photo</strong> → Checks Cloudinary link in <code>student_verification.link</code></li>
                            <li><strong>Ofelia Approves</strong> → Updates:
                                <ul>
                                    <li><code>student_verification.is_verified = true</code></li>
                                    <li><code>users.is_active = true</code></li>
                                </ul>
                            </li>
                            <li><strong>Student Gets Notification</strong> → Can now login and access marketplace</li>
                        </ol>
                    </div>
                </section>

                <!-- Testing -->
                <section id="testing">
                    <h2>🧪 Testing</h2>

                    <h3>Test Server URL</h3>
                    <div class="info-box">
                        <strong>Base URL:</strong> <code>http://localhost:8000</code>
                    </div>

                    <h3>Quick cURL Test</h3>
                    <div class="code-block">
curl -X POST http://localhost:8000/api/register \
  -F "first_name=Juan" \
  -F "last_name=Dela Cruz" \
  -F "email=juan.delacruz@olfu.edu.ph" \
  -F "password=SecurePass123" \
  -F "password_confirmation=SecurePass123" \
  -F "student_id_photo=@./id_photo.jpg"
                    </div>

                    <h3>Using Postman</h3>
                    <ol>
                        <li>Create a new POST request to <code>http://localhost:8000/api/register</code></li>
                        <li>Go to "Body" tab → Select "form-data"</li>
                        <li>Add the parameters as shown above</li>
                        <li>For the file, select "File" from the dropdown for <code>student_id_photo</code></li>
                        <li>Click "Send"</li>
                    </ol>
                </section>

                <div style="text-align: center; margin-top: 50px; padding-top: 30px; border-top: 2px solid #eee;">
                    <p style="color: #999;">Last Updated: {{ date('F d, Y') }} | Fati-Market Backend API v1.0</p>
                </div>
            </main>
        </div>

        <footer>
            <p>🔗 For questions or issues, contact the development team</p>
        </footer>
    </div>
</body>
</html>
