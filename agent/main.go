package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"regexp"
)

// Config holds agent parameters
type Config struct {
	Port  string `json:"port"`
	Token string `json:"token"`
}

// Request payload
type CommandRequest struct {
	Action string `json:"action"`
	Target string `json:"target,omitempty"`
	IP     string `json:"ip,omitempty"`
}

// Response payload
type CommandResponse struct {
	Status  string `json:"status"`
	Output  string `json:"output,omitempty"`
	Message string `json:"message,omitempty"`
}

var (
	token          string
	targetRegex    = regexp.MustCompile(`^(\*|[a-zA-Z0-9\.\-]+)\/(\*|[0-9\.]+)$`)
	plainTargetReg = regexp.MustCompile(`^[a-zA-Z0-9\.\-\*\/]+$`)
)

func main() {
	// Initialize configurations from env or fallback file
	port := os.Getenv("AGENT_PORT")
	if port == "" {
		port = "8443"
	}

	token = os.Getenv("SENDER_INTERNAL_KEY")
	if token == "" {
		// Attempt to read config.json
		configFile, err := os.Open("config.json")
		if err == nil {
			defer configFile.Close()
			var cfg Config
			dec := json.NewDecoder(configFile)
			if err := dec.Decode(&cfg); err == nil {
				token = cfg.Token
				if cfg.Port != "" {
					port = cfg.Port
				}
			}
		}
	}

	if token == "" {
		log.Println("⚠️ WARNING: SENDER_INTERNAL_KEY/token is not configured. Falling back to local development defaults.")
		token = "super_secret_global_key"
	}

	http.HandleFunc("/run", handleCommand)

	log.Printf("🚀 PMTA Remote Agent listening securely on port %s...\n", port)
	if err := http.ListenAndServe(":"+port, nil); err != nil {
		log.Fatalf("❌ Failed to start server: %v", err)
	}
}

func handleCommand(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		json.NewEncoder(w).Encode(CommandResponse{Status: "error", Message: "Method not allowed"})
		return
	}

	// Validate authorization token (timing-safe comparison is recommended, simple check for now)
	reqToken := r.Header.Get("X-Agent-Token")
	if reqToken == "" {
		reqToken = r.Header.Get("X-Internal-Key") // backwards compatibility
	}

	if reqToken != token {
		w.WriteHeader(http.StatusForbidden)
		json.NewEncoder(w).Encode(CommandResponse{Status: "error", Message: "Forbidden"})
		return
	}

	var req CommandRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		w.WriteHeader(http.StatusBadRequest)
		json.NewEncoder(w).Encode(CommandResponse{Status: "error", Message: "Invalid JSON request payload"})
		return
	}

	output, err := runAction(req)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		json.NewEncoder(w).Encode(CommandResponse{Status: "error", Message: err.Error(), Output: output})
		return
	}

	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(CommandResponse{Status: "success", Output: output})
}

func runAction(req CommandRequest) (string, error) {
	var cmdName string
	var args []string

	// Strict Action Whitelist & Argument Sanitization
	switch req.Action {
	case "enable_source":
		if req.IP == "" || req.Target == "" {
			return "", fmt.Errorf("ip and target parameters are required")
		}
		if net.ParseIP(req.IP) == nil {
			return "", fmt.Errorf("invalid IP format")
		}
		if !targetRegex.MatchString(req.Target) && !plainTargetReg.MatchString(req.Target) {
			return "", fmt.Errorf("invalid target pattern format")
		}
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "enable", "source", req.IP, req.Target}

	case "pause_queue":
		if req.Target == "" {
			return "", fmt.Errorf("target parameter is required")
		}
		if !targetRegex.MatchString(req.Target) && !plainTargetReg.MatchString(req.Target) {
			return "", fmt.Errorf("invalid target pattern format")
		}
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "pause", "queue", req.Target}

	case "resume_queue":
		if req.Target == "" {
			return "", fmt.Errorf("target parameter is required")
		}
		if !targetRegex.MatchString(req.Target) && !plainTargetReg.MatchString(req.Target) {
			return "", fmt.Errorf("invalid target pattern format")
		}
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "resume", "queue", req.Target}

	case "delete_queue":
		if req.Target == "" {
			return "", fmt.Errorf("target parameter is required")
		}
		if !targetRegex.MatchString(req.Target) && !plainTargetReg.MatchString(req.Target) {
			return "", fmt.Errorf("invalid target pattern format")
		}
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "delete", "-queue=" + req.Target}

	case "reload":
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "reload"}

	case "restart":
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/bin/systemctl", "restart", "pmta.service"}

	case "reset_counters":
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "reset", "counters"}

	case "status":
		cmdName = "/usr/bin/sudo"
		args = []string{"/usr/sbin/pmta", "show", "status"}

	default:
		return "", fmt.Errorf("action '%s' is not whitelisted", req.Action)
	}

	// execute securely bypassing command shell interpreters
	cmd := exec.Command(cmdName, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return string(out), fmt.Errorf("command execution failed: %w", err)
	}

	return string(out), nil
}
