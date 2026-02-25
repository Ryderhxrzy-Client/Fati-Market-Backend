<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - Fati-Market</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background: #f5f5f5;
            min-height: 100vh;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #fff;
            border-right: 1px solid #e0e0e0;
            padding: 30px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 20px 30px;
            border-bottom: 1px solid #e0e0e0;
        }

        .sidebar-header h1 {
            font-size: 1.3em;
            color: #333;
            font-weight: 700;
        }

        .sidebar-section {
            margin: 20px 0;
        }

        .sidebar-section-title {
            padding: 10px 20px;
            font-size: 0.85em;
            font-weight: 700;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar-list {
            list-style: none;
        }

        .sidebar-list li {
            margin: 0;
        }

        .sidebar-list a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #666;
            text-decoration: none;
            font-size: 0.95em;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-list a:hover {
            color: #333;
            background: #f5f5f5;
            border-left-color: #999;
        }

        .sidebar-list a.active {
            color: #000;
            background: #f0f0f0;
            border-left-color: #333;
            font-weight: 600;
        }

        .sidebar-list i {
            width: 18px;
            text-align: center;
            color: #999;
        }

        .sidebar-list a:hover i {
            color: #333;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 40px;
        }

        header {
            background: #fff;
            padding: 30px;
            border-radius: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 40px;
        }

        header h1 {
            font-size: 2em;
            color: #333;
            margin-bottom: 5px;
            font-weight: 700;
        }

        header p {
            font-size: 1em;
            color: #999;
        }

        section {
            background: #fff;
            padding: 30px;
            border-radius: 0;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            scroll-margin-top: 20px;
        }

        h2 {
            color: #333;
            font-size: 1.7em;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
            font-weight: 700;
        }

        h3 {
            color: #555;
            font-size: 1.3em;
            margin-top: 25px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        h4 {
            color: #555;
            font-size: 1.05em;
            margin-top: 15px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        p {
            margin-bottom: 15px;
            color: #666;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f8f8;
            color: #333;
            font-weight: 600;
        }

        tr:hover {
            background: #fafafa;
        }

        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            overflow-x: auto;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.85em;
            line-height: 1.5;
            border-left: 3px solid #666;
        }

        .success-box {
            background: #f0f9f6;
            border-left: 3px solid #27a745;
            padding: 15px;
            margin: 15px 0;
            color: #155724;
        }

        .error-box {
            background: #fdf8f8;
            border-left: 3px solid #dc3545;
            padding: 15px;
            margin: 15px 0;
            color: #721c24;
        }

        .info-box {
            background: #f0f6ff;
            border-left: 3px solid #0066cc;
            padding: 15px;
            margin: 15px 0;
            color: #003d99;
        }

        .warning-box {
            background: #fffbf0;
            border-left: 3px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            color: #856404;
        }

        .endpoint-card {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 20px;
            margin: 15px 0;
        }

        .endpoint-card h4 {
            margin-top: 0;
            color: #333;
        }

        .method {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 0.8em;
            margin-right: 10px;
        }

        .method-post {
            background: #333;
            color: white;
        }

        .method-get {
            background: #666;
            color: white;
        }

        .param-required {
            color: #dc3545;
            font-weight: 600;
        }

        ul {
            margin-left: 20px;
            margin-bottom: 15px;
        }

        li {
            margin-bottom: 8px;
            color: #666;
        }

        ol {
            margin-left: 20px;
            margin-bottom: 15px;
        }

        ol li {
            margin-bottom: 10px;
        }

        footer {
            text-align: center;
            padding: 20px;
            color: #999;
            margin-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 0.9em;
        }

        @media (max-width: 768px) {
            .wrapper {
                flex-direction: column;
            }

            .sidebar {
                position: static;
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
                padding: 20px 0;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            header {
                padding: 20px;
            }

            header h1 {
                font-size: 1.5em;
            }

            section {
                padding: 20px;
            }

            h2 {
                font-size: 1.4em;
            }

            .sidebar-section {
                display: inline-block;
                margin-right: 20px;
            }

            .sidebar-list a {
                padding: 8px 15px;
            }
        }

        code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }

        /* Collapsible Sections */
        .collapsible-header {
            cursor: pointer;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin: 15px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            transition: all 0.2s ease;
        }

        .collapsible-header:hover {
            background: #f0f0f0;
        }

        .collapsible-header.active {
            background: #f0f0f0;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }

        .collapsible-header i {
            transition: transform 0.2s ease;
        }

        .collapsible-header.active i {
            transform: rotate(180deg);
        }

        .collapsible-content {
            display: none;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-top: none;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
            padding: 20px;
            margin: -15px 0 15px 0;
        }

        .collapsible-content.active {
            display: block;
        }

        .error-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .error-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Fati-Market</h1>
            </div>

            <!-- Authentication APIs -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Authentication</div>
                <ul class="sidebar-list">
                    <li><a href="#register" onclick="setActive(this)" class="active"><i class="fas fa-user-plus"></i> Register</a></li>
                    <li><a href="#login" onclick="setActive(this)"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                </ul>
            </div>

            <!-- User APIs -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Users</div>
                <ul class="sidebar-list">
                    <li><a href="#profile" onclick="setActive(this)"><i class="fas fa-user-circle"></i> Get Profile</a></li>
                    <li><a href="#update-profile" onclick="setActive(this)"><i class="fas fa-edit"></i> Update Profile</a></li>
                </ul>
            </div>

            <!-- Items APIs -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Items & Marketplace</div>
                <ul class="sidebar-list">
                    <li><a href="#create-item" onclick="setActive(this)"><i class="fas fa-plus-circle"></i> Create Item</a></li>
                    <li><a href="#list-items" onclick="setActive(this)"><i class="fas fa-list"></i> List Items</a></li>
                    <li><a href="#get-item" onclick="setActive(this)"><i class="fas fa-search"></i> Get Item</a></li>
                </ul>
            </div>

            <!-- Other Resources -->
            <div class="sidebar-section">
                <div class="sidebar-section-title">Resources</div>
                <ul class="sidebar-list">
                    <li><a href="#database" onclick="setActive(this)"><i class="fas fa-database"></i> Database Schema</a></li>
                    <li><a href="#testing" onclick="setActive(this)"><i class="fas fa-flask"></i> Testing</a></li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>API Documentation</h1>
                <p>Fati-Market Backend - Kotlin Mobile App</p>
            </header>

            <!-- Register Endpoint -->
            <section id="register">
                <h2><i class="fas fa-user-plus"></i> Student Registration</h2>
                <p>Register a new student account with email, password, and student ID verification photo.</p>

                <div class="endpoint-card">
                    <h4><span class="method method-post">POST</span> /api/register</h4>
                    <p><strong>Authentication:</strong> Not required</p>
                    <p><strong>Content-Type:</strong> multipart/form-data</p>
                </div>

                <h3>Request Parameters</h3>
                <table>
                    <tr>
                        <th>Field</th>
                        <th>Type</th>
                        <th>Required</th>
                        <th>Constraints</th>
                    </tr>
                    <tr>
                        <td><code>first_name</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Max 255 chars</td>
                    </tr>
                    <tr>
                        <td><code>last_name</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Max 255 chars</td>
                    </tr>
                    <tr>
                        <td><code>email</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Must end with @student.fatima.edu.ph, unique</td>
                    </tr>
                    <tr>
                        <td><code>password</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Min 8 characters</td>
                    </tr>
                    <tr>
                        <td><code>password_confirmation</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Must match password</td>
                    </tr>
                    <tr>
                        <td><code>student_id_photo</code></td>
                        <td>file</td>
                        <td><span class="param-required">✓</span></td>
                        <td>jpg/jpeg/png, max 5MB</td>
                    </tr>
                    <tr>
                        <td><code>verification_use</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Either: registration_card OR student_id</td>
                    </tr>
                </table>

                <h3>Success Response (HTTP 201)</h3>
                <div class="success-box">
                    <strong>Status: 201 Created</strong>
                </div>

                <div class="code-block">
{
  "message": "Registration successful. Please wait for admin approval.",
  "data": {
    "user_id": 42,
    "student_id": 15,
    "email": "juan.delacruz@student.fatima.edu.ph",
    "first_name": "Juan",
    "last_name": "Dela Cruz"
  }
}
                </div>

                <h3>Error Responses</h3>

                <div class="collapsible-header" onclick="toggleCollapsible(this)">
                    <span>View Error Responses</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="collapsible-content">
                    <div class="error-item">
                        <h4>Invalid Email Domain (HTTP 422)</h4>
                        <div class="error-box">Email must end with @student.fatima.edu.ph</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field must end with @student.fatima.edu.ph."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Email Already Taken (HTTP 422)</h4>
                        <div class="error-box">Email address already registered</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Password Too Short (HTTP 422)</h4>
                        <div class="error-box">Password must be at least 8 characters</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "password": ["The password field must be at least 8 characters."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Passwords Don't Match (HTTP 422)</h4>
                        <div class="error-box">Confirmation password doesn't match</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "password": ["The password field confirmation does not match."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Invalid Photo Format (HTTP 422)</h4>
                        <div class="error-box">Must be jpg, jpeg, or png file</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "student_id_photo": ["The student id photo field must be a valid image."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Photo File Too Large (HTTP 422)</h4>
                        <div class="error-box">Max file size is 5MB</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "student_id_photo": ["The student id photo field must not be greater than 5120 kilobytes."]
  }
}
                        </div>
                    </div>
                </div>
            </section>

            <!-- Login Endpoint -->
            <section id="login">
                <h2><i class="fas fa-sign-in-alt"></i> Student Login</h2>
                <p>Authenticate a student account using email and password. Account must be approved by admin to login.</p>

                <div class="endpoint-card">
                    <h4><span class="method method-post">POST</span> /api/login</h4>
                    <p><strong>Authentication:</strong> Not required</p>
                    <p><strong>Content-Type:</strong> application/json</p>
                </div>

                <h3>Request Parameters</h3>
                <table>
                    <tr>
                        <th>Field</th>
                        <th>Type</th>
                        <th>Required</th>
                        <th>Constraints</th>
                    </tr>
                    <tr>
                        <td><code>email</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Must end with @student.fatima.edu.ph</td>
                    </tr>
                    <tr>
                        <td><code>password</code></td>
                        <td>string</td>
                        <td><span class="param-required">✓</span></td>
                        <td>Plain text password</td>
                    </tr>
                </table>

                <h3>Success Response (HTTP 200)</h3>
                <div class="success-box">
                    <strong>Status: 200 OK</strong>
                </div>

                <div class="code-block">
{
  "message": "Login successful",
  "data": {
    "user_id": 42,
    "email": "juan.delacruz@student.fatima.edu.ph",
    "first_name": "Juan",
    "last_name": "Dela Cruz",
    "profile_picture": "https://res.cloudinary.com/...",
    "role": "student",
    "wallet_points": 0
  }
}
                </div>

                <h3>Error Responses</h3>

                <div class="collapsible-header" onclick="toggleCollapsible(this)">
                    <span>View Error Responses</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="collapsible-content">
                    <div class="error-item">
                        <h4>Invalid Email Domain (HTTP 422)</h4>
                        <div class="error-box">Email must end with @student.fatima.edu.ph</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field must end with @student.fatima.edu.ph."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Invalid Credentials (HTTP 401)</h4>
                        <div class="error-box">Email or password is incorrect</div>
                        <div class="code-block">
{
  "message": "Invalid credentials"
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Account Not Approved (HTTP 403)</h4>
                        <div class="error-box">Admin has not yet approved this account</div>
                        <div class="code-block">
{
  "message": "Account is not yet approved by admin"
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Missing Required Fields (HTTP 422)</h4>
                        <div class="error-box">Email or password field is missing</div>
                        <div class="code-block">
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field is required."]
  }
}
                        </div>
                    </div>

                    <div class="error-item">
                        <h4>Server Error (HTTP 500)</h4>
                        <div class="error-box">Unexpected server error during login</div>
                        <div class="code-block">
{
  "message": "Login failed",
  "error": "Error details here"
}
                        </div>
                    </div>
                </div>
            </section>

            <!-- Database Schema -->
            <section id="database">
                <h2><i class="fas fa-database"></i> Database Schema</h2>
                <p>Three tables are created on successful registration:</p>

                <h3>users Table</h3>
                <div class="code-block">
user_id: INT (PK, auto-increment)
email: VARCHAR (UNIQUE)
password: VARCHAR (bcrypt hashed)
wallet_points: INT (default: 0)
role: ENUM ('student', 'admin')
is_active: BOOLEAN (default: FALSE)
created_at: TIMESTAMP
updated_at: TIMESTAMP
                </div>

                <h3>student_information Table</h3>
                <div class="code-block">
student_id: INT (PK, auto-increment)
user_id: INT (FK)
first_name: VARCHAR
last_name: VARCHAR
profile_picture: VARCHAR (Cloudinary URL)
created_at: TIMESTAMP
updated_at: TIMESTAMP
                </div>

                <h3>student_verification Table</h3>
                <div class="code-block">
student_id: INT (PK, auto-increment)
user_id: INT (FK)
verification_use: ENUM ('registration_card', 'student_id')
link: VARCHAR (Cloudinary URL)
is_verified: BOOLEAN (default: FALSE)
created_at: TIMESTAMP
updated_at: TIMESTAMP
                </div>
            </section>

            <!-- Testing -->
            <section id="testing">
                <h2><i class="fas fa-flask"></i> Testing</h2>

                <h3>cURL Command</h3>
                <div class="code-block">
curl -X POST http://localhost:8000/api/register \
  -F "first_name=Juan" \
  -F "last_name=Dela Cruz" \
  -F "email=juan.delacruz@student.fatima.edu.ph" \
  -F "password=SecurePass123" \
  -F "password_confirmation=SecurePass123" \
  -F "verification_use=student_id" \
  -F "student_id_photo=@./id_photo.jpg"
                </div>

                <h3>Kotlin with OkHttp3</h3>
                <div class="code-block">
val requestBody = MultipartBody.Builder()
    .setType(MultipartBody.FORM)
    .addFormDataPart("first_name", "Juan")
    .addFormDataPart("last_name", "Dela Cruz")
    .addFormDataPart("email", "juan.delacruz@student.fatima.edu.ph")
    .addFormDataPart("password", "SecurePass123")
    .addFormDataPart("password_confirmation", "SecurePass123")
    .addFormDataPart("verification_use", "student_id")
    .addFormDataPart(
        "student_id_photo",
        photoFile.name,
        photoFile.asRequestBody("image/jpeg".toMediaType())
    )
    .build()

val request = Request.Builder()
    .url("http://localhost:8000/api/register")
    .post(requestBody)
    .build()

client.newCall(request).enqueue(object : Callback {
    override fun onResponse(call: Call, response: Response) {
        // Handle response
    }
    override fun onFailure(call: Call, e: IOException) {
        // Handle error
    }
})
                </div>

                <p style="color: #999; margin-top: 20px; text-align: center;">Ready to test? Share this documentation with your Kotlin developer!</p>
            </section>

            <footer>
                Last updated: {{ date('F d, Y') }} | Fati-Market API v1.0
            </footer>
        </div>
    </div>

    <script>
        function setActive(element) {
            document.querySelectorAll('.sidebar-list a').forEach(a => {
                a.classList.remove('active');
            });
            element.classList.add('active');
        }

        function toggleCollapsible(header) {
            header.classList.toggle('active');
            const content = header.nextElementSibling;
            content.classList.toggle('active');
        }
    </script>
</body>
</html>
